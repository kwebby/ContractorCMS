<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace Tests\Feature;

use App\Contracts\RecordRepository;
use App\Finance\FinanceService;
use App\Identity\CmsUser;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FinanceWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private RecordRepository $records;

    private FinanceService $finance;

    private array $customer;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cms.data_provider' => 'postgres', 'cms.mfa_required' => false]);
        if (! Route::has('invoices.pdf')) {
            Route::middleware('web')->group(base_path('routes/finance.php'));
        }
        $this->records = app(RecordRepository::class);
        $this->finance = app(FinanceService::class);
        $this->customer = $this->records->create('customers', ['name' => 'Avery Client', 'email' => 'avery@example.test', 'address' => '1 Test Street']);
        Settings::put('business', ['legal_name' => 'Sample Plumbing LLC', 'email' => 'office@example.test', 'address' => '2 Workshop Lane']);
        $this->actingAs(new CmsUser(['id' => 'owner', 'role' => 'owner', 'name' => 'Owner', 'email' => 'owner@example.test']));
        Http::preventStrayRequests();
    }

    private function draft(): array
    {
        return $this->finance->draft('invoices', ['customer_id' => $this->customer['id'], 'title' => 'Drain repair', 'currency' => 'USD', 'items' => [['description' => 'Labor', 'quantity' => '1.5', 'unit_price_minor' => 10000, 'tax_basis_points' => 825]]]);
    }

    public function test_issuance_preserves_snapshot_and_prevents_editing(): void
    {
        $invoice = $this->finance->issue($this->draft()['id']);
        $this->assertSame(16238, $invoice['total_minor']);
        Settings::put('business', ['legal_name' => 'Renamed company']);
        $this->assertSame('Sample Plumbing LLC', $invoice['snapshot']['business']['legal_name']);
        $this->assertSame('INV-000001', $invoice['number']);
        $this->assertSame($invoice['number'], $this->finance->issue($invoice['id'])['number']);
        $this->putJson('/api/v1/invoices/'.$invoice['id'], ['version' => $invoice['version'], 'title' => 'Changed', 'items' => [['description' => 'Changed', 'quantity' => '1', 'unit_price_minor' => 1]]])->assertUnprocessable();
    }

    public function test_partial_payment_retry_overpayment_credit_and_refund_have_correct_balances(): void
    {
        $invoice = $this->finance->issue($this->draft()['id']);
        $payment = $this->finance->payment($invoice['id'], 6000, 'cash', 'Receipt 1', 'offline-payment-1');
        $this->finance->payment($invoice['id'], 6000, 'cash', 'Receipt 1', 'offline-payment-1');
        $this->assertCount(1, $this->records->all('payments'));
        $this->assertSame(10238, $this->finance->balance($invoice)['balance_minor']);
        $this->finance->payment($invoice['id'], 11000, 'bank_transfer', 'Bank 2', 'offline-payment-2');
        $this->assertSame(762, $this->finance->balance($invoice)['overpayment_minor']);
        $this->finance->refund($payment['id'], 1000, 'Adjustment', 'refund-key-1');
        $this->assertSame(238, $this->finance->balance($invoice)['balance_minor']);
        $this->finance->credit($invoice['id'], 238, 'Goodwill adjustment', 'credit-key-1');
        $this->assertSame(0, $this->finance->balance($invoice)['balance_minor']);
        $this->assertSame('paid', $this->finance->balance($invoice)['payment_status']);
    }

    public function test_refunds_cannot_exceed_settled_payment(): void
    {
        $invoice = $this->finance->issue($this->draft()['id']);
        $payment = $this->finance->payment($invoice['id'], 6000, 'cash', 'Receipt 1', 'offline-payment-1');
        $this->finance->refund($payment['id'], 5000, 'First refund', 'refund-one');
        $this->expectException(ValidationException::class);
        $this->finance->refund($payment['id'], 1001, 'Excess refund', 'refund-two');
    }

    public function test_client_cannot_read_another_customer_invoice_or_record_payments(): void
    {
        $invoice = $this->finance->issue($this->draft()['id']);
        $this->actingAs(new CmsUser(['id' => 'client-other', 'role' => 'client', 'customer_id' => 'other', 'email_verified_at' => now()->toISOString()]));
        $this->getJson('/api/v1/invoices/'.$invoice['id'])->assertForbidden();
        $this->get('/api/v1/invoices/'.$invoice['id'].'/pdf')->assertForbidden();
        $this->postJson('/api/v1/invoices/'.$invoice['id'].'/payments', ['amount_minor' => 1, 'method' => 'cash', 'reference' => 'fake', 'idempotency_key' => 'fake-payment-key'])->assertForbidden();
    }

    public function test_client_sees_only_issued_own_invoices(): void
    {
        $issued = $this->finance->issue($this->draft()['id']);
        $draft = $this->draft();
        $this->actingAs(new CmsUser(['id' => 'client-own', 'role' => 'client', 'customer_id' => $this->customer['id'], 'email_verified_at' => now()->toISOString()]));
        $this->getJson('/api/v1/invoices')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $issued['id']);
        $this->getJson('/api/v1/invoices/'.$draft['id'])->assertForbidden();
    }

    public function test_acceptance_requires_exact_revision_and_creates_one_job(): void
    {
        $invoice = $this->draft();
        $estimate = $this->finance->draft('estimates', ['customer_id' => $this->customer['id'], 'title' => 'Repair', 'currency' => 'USD', 'items' => $invoice['items']]);
        $estimate = $this->finance->sendEstimate($estimate['id']);
        $this->postJson('/api/v1/estimates/'.$estimate['id'].'/accept', ['version' => $estimate['version'] - 1])->assertUnprocessable();
        $first = $this->finance->acceptEstimate($estimate['id'], $estimate['version'], 'owner', '127.0.0.1');
        $second = $this->finance->acceptEstimate($estimate['id'], $estimate['version'], 'owner', '127.0.0.1');
        $this->assertSame($first['job_id'], $second['job_id']);
        $this->assertCount(1, $this->records->all('jobs'));
    }

    public function test_pdf_uses_immutable_issue_snapshot_and_is_private(): void
    {
        Storage::fake('local');
        $invoice = $this->finance->issue($this->draft()['id']);
        $response = $this->get('/api/v1/invoices/'.$invoice['id'].'/pdf');
        $response->assertOk()->assertDownload($invoice['number'].'.pdf');
        $this->assertStringStartsWith('%PDF-', Storage::disk('local')->get('documents/invoices/'.$invoice['id'].'.pdf'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
    }

    public function test_gateway_disabled_until_configured(): void
    {
        $invoice = $this->finance->issue($this->draft()['id']);
        $this->postJson('/api/v1/invoices/'.$invoice['id'].'/checkout', ['provider' => 'stripe'])->assertUnprocessable()->assertJsonValidationErrors('provider');
        Http::assertNothingSent();
    }

    public function test_stripe_signature_amount_checks_and_duplicate_delivery(): void
    {
        Settings::put('payments', ['stripe_enabled' => true, 'stripe_secret_key' => 'sk_test_fake', 'stripe_webhook_secret' => 'whsec_test']);
        $invoice = $this->finance->issue($this->draft()['id']);
        $attempt = $this->records->create('payment_attempts', ['invoice_id' => $invoice['id'], 'provider' => 'stripe', 'provider_id' => 'cs_test_example', 'status' => 'ready', 'amount_minor' => $invoice['total_minor'], 'currency' => 'USD']);
        $event = ['id' => 'evt_test_one', 'object' => 'event', 'type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_test_example', 'object' => 'checkout.session', 'payment_status' => 'paid', 'currency' => 'usd', 'amount_total' => $invoice['total_minor'], 'payment_intent' => 'pi_test_one', 'metadata' => ['invoice_id' => $invoice['id'], 'attempt_id' => $attempt['id']]]]];
        $payload = json_encode($event, JSON_THROW_ON_ERROR);
        $this->call('POST', '/api/v1/payments/webhooks/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => 'invalid'], $payload)->assertBadRequest();
        $this->assertCount(0, $this->records->all('payments'));
        $signature = 't='.time().',v1='.hash_hmac('sha256', time().'.'.$payload, 'whsec_test');
        for ($i = 0; $i < 2; $i++) {
            $this->call('POST', '/api/v1/payments/webhooks/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $signature], $payload)->assertOk();
        }
        $this->assertCount(1, $this->records->all('payments'));
        $this->assertSame(0, $this->finance->balance($invoice)['balance_minor']);
        $event['id'] = 'evt_wrong_amount';
        $event['data']['object']['amount_total']--;
        $payload = json_encode($event, JSON_THROW_ON_ERROR);
        $signature = 't='.time().',v1='.hash_hmac('sha256', time().'.'.$payload, 'whsec_test');
        $this->call('POST', '/api/v1/payments/webhooks/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $signature], $payload)->assertUnprocessable();
    }

    public function test_refund_requires_recent_password_confirmation(): void
    {
        $invoice = $this->finance->issue($this->draft()['id']);
        $payment = $this->finance->payment($invoice['id'], 6000, 'cash', 'Receipt', 'cash-payment');
        $this->postJson('/api/v1/payments/'.$payment['id'].'/refund', ['amount_minor' => 1000, 'reason' => 'Refund requested', 'idempotency_key' => 'requested-refund'])->assertStatus(423);
        $this->withSession(['auth.password_confirmed_at' => now()->timestamp])->postJson('/api/v1/payments/'.$payment['id'].'/refund', ['amount_minor' => 1000, 'reason' => 'Refund requested', 'idempotency_key' => 'requested-refund'])->assertOk();
    }

    public function test_deposit_allocation_is_atomic_and_cannot_move_between_customers(): void
    {
        $deposit = $this->finance->deposit(['customer_id' => $this->customer['id'], 'currency' => 'USD', 'amount_minor' => 5000, 'method' => 'cash', 'reference' => 'Deposit receipt', 'idempotency_key' => 'deposit-test-key']);
        $invoice = $this->finance->issue($this->draft()['id']);
        $allocation = $this->finance->allocateDeposit($deposit['id'], $invoice['id'], 3000, 'allocation-test');
        $this->finance->allocateDeposit($deposit['id'], $invoice['id'], 3000, 'allocation-test');
        $this->assertSame(2000, $this->finance->depositBalance($deposit)['available_minor']);
        $this->assertCount(1, $this->records->all('payments'));
        $this->assertSame(13238, $this->finance->balance($invoice)['balance_minor']);
        $this->postJson('/api/v1/customer-deposits/'.$deposit['id'].'/allocate', ['invoice_id' => $invoice['id'], 'amount_minor' => 2001, 'idempotency_key' => 'overspend-deposit'])->assertUnprocessable();
        $this->assertSame(2000, $this->finance->depositBalance($deposit)['available_minor']);
        $this->finance->refundDeposit($deposit['id'], 2000, 'Unused deposit', 'unused-refund');
        $this->assertSame(0, $this->finance->depositBalance($deposit)['available_minor']);
        $this->get('/api/v1/payments/'.$allocation['payment_id'].'/receipt')->assertOk();
    }

    public function test_overpaid_customer_credit_can_be_applied_to_another_invoice_once(): void
    {
        $source = $this->finance->issue($this->draft()['id']);
        $target = $this->finance->issue($this->draft()['id']);
        $this->finance->payment($source['id'], 20000, 'cash', 'Cash', 'overpaid-cash');
        $this->finance->transferCredit($source['id'], $target['id'], 3762, 'credit-move-key');
        $this->finance->transferCredit($source['id'], $target['id'], 3762, 'credit-move-key');
        $this->assertSame(0, $this->finance->balance($source)['overpayment_minor']);
        $this->assertSame(12476, $this->finance->balance($target)['balance_minor']);
        $this->assertCount(1, $this->records->all('credit_transfers'));
    }

    public function test_paypal_requires_provider_verified_event_before_settling(): void
    {
        Settings::put('payments', ['paypal_enabled' => true, 'paypal_client_id' => 'test-client', 'paypal_client_secret' => 'test-secret', 'paypal_webhook_id' => 'test-webhook', 'paypal_mode' => 'sandbox']);
        $invoice = $this->finance->issue($this->draft()['id']);
        $this->records->create('payment_attempts', ['invoice_id' => $invoice['id'], 'provider' => 'paypal', 'provider_id' => 'ORDER-1', 'status' => 'ready', 'amount_minor' => $invoice['total_minor'], 'currency' => 'USD']);
        $event = ['id' => 'paypal-event-one', 'event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'resource' => ['id' => 'CAPTURE-1', 'amount' => ['value' => '162.38', 'currency_code' => 'USD'], 'supplementary_data' => ['related_ids' => ['order_id' => 'ORDER-1']]]];
        Http::fake(['*/v1/oauth2/token' => Http::response(['access_token' => 'test-token']), '*/v1/notifications/verify-webhook-signature' => Http::sequence()->push(['verification_status' => 'FAILURE'])->push(['verification_status' => 'SUCCESS'])->push(['verification_status' => 'SUCCESS'])]);
        $this->postJson('/api/v1/payments/webhooks/paypal', $event)->assertBadRequest();
        $this->assertCount(0, $this->records->all('payments'));
        $this->postJson('/api/v1/payments/webhooks/paypal', $event)->assertOk();
        $this->postJson('/api/v1/payments/webhooks/paypal', $event)->assertOk();
        $this->assertCount(1, $this->records->all('payments'));
        $this->assertSame(0, $this->finance->balance($invoice)['balance_minor']);
    }

    public function test_stripe_refund_webhook_settles_reserved_refund_without_double_counting(): void
    {
        Settings::put('payments', ['stripe_enabled' => true, 'stripe_secret_key' => 'sk_test_fake', 'stripe_webhook_secret' => 'whsec_test']);
        $invoice = $this->finance->issue($this->draft()['id']);
        $payment = $this->finance->payment($invoice['id'], 6000, 'stripe', 'session-one', 'stripe-session-one', ['payment_intent' => 'pi_one']);
        $refund = $this->finance->refund($payment['id'], 2000, 'Requested refund', 'local-refund-key', 'pending');
        $event = ['id' => 'evt_refund_test', 'object' => 'event', 'type' => 'charge.refunded', 'data' => ['object' => ['id' => 'ch_one', 'payment_intent' => 'pi_one', 'refunds' => ['data' => [['id' => 're_one', 'status' => 'succeeded', 'amount' => 2000, 'currency' => 'usd', 'metadata' => ['local_refund_id' => $refund['id']]]]]]]];
        $payload = json_encode($event, JSON_THROW_ON_ERROR);
        $time = time();
        $signature = 't='.$time.',v1='.hash_hmac('sha256', $time.'.'.$payload, 'whsec_test');
        $this->call('POST', '/api/v1/payments/webhooks/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $signature], $payload)->assertOk();
        $this->assertCount(1, $this->records->all('refunds'));
        $this->assertSame('settled', $this->records->get('refunds', $refund['id'])['status']);
        $this->assertSame(4000, $this->finance->balance($invoice)['paid_minor']);
    }
}

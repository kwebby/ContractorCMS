<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace Tests\Feature;

use App\Contracts\RecordRepository;
use App\Finance\FinanceService;
use App\Finance\PaymentGateway;
use App\Identity\CmsUser;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FinanceHardeningTest extends TestCase
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

    private function invoice(): array
    {
        $draft = $this->finance->draft('invoices', ['customer_id' => $this->customer['id'], 'title' => 'Drain repair', 'currency' => 'USD', 'items' => [['description' => 'Labor', 'quantity' => '1.5', 'unit_price_minor' => 10000, 'tax_basis_points' => 825]]]);

        return $this->finance->issue($draft['id']);
    }

    private function enableStripe(): void
    {
        Settings::put('payments', ['stripe_enabled' => true, 'stripe_secret_key' => 'sk_test_fake', 'stripe_webhook_secret' => 'whsec_test']);
    }

    private function enablePaypal(): void
    {
        Settings::put('payments', ['paypal_enabled' => true, 'paypal_client_id' => 'test-client', 'paypal_client_secret' => 'test-secret', 'paypal_webhook_id' => 'test-webhook', 'paypal_mode' => 'sandbox']);
    }

    private function fakePaypal(array $routes = []): void
    {
        Http::fake($routes + ['*/v1/oauth2/token' => Http::response(['access_token' => 'test-token', 'expires_in' => 32400]), '*/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'SUCCESS'])]);
    }

    private function stripeEvent(array $event): TestResponse
    {
        $payload = json_encode($event + ['object' => 'event'], JSON_THROW_ON_ERROR);
        $time = time();
        $signature = 't='.$time.',v1='.hash_hmac('sha256', $time.'.'.$payload, 'whsec_test');

        return $this->call('POST', '/api/v1/payments/webhooks/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $signature], $payload);
    }

    private function assertRejected(callable $callback): void
    {
        try {
            $callback();
        } catch (ValidationException) {
            $this->addToAssertionCount(1);

            return;
        }
        $this->fail('The finance operation was expected to be rejected.');
    }

    public function test_refunds_cannot_exceed_money_still_held_after_credit_moves_to_another_invoice(): void
    {
        $source = $this->invoice();
        $target = $this->invoice();
        $payment = $this->finance->payment($source['id'], 20000, 'cash', 'Cash', 'overpaid-cash');
        $this->finance->transferCredit($source['id'], $target['id'], 3762, 'credit-move-key');

        $this->assertRejected(fn () => $this->finance->refund($payment['id'], 20000, 'Full refund', 'refund-everything'));
        $this->assertRejected(fn () => $this->finance->refund($payment['id'], 16239, 'Too much', 'refund-too-much'));
        $this->finance->refund($payment['id'], 16238, 'Job cancelled', 'refund-held');
        $this->assertRejected(fn () => $this->finance->refund($payment['id'], 1, 'One more', 'refund-one-more'));

        $this->assertSame(0, $this->finance->balance($source)['paid_minor']);
        $this->assertSame(16238, array_sum(array_column($this->records->all('refunds'), 'amount_minor')));
        $this->assertSame(12476, $this->finance->balance($target)['balance_minor']);
    }

    public function test_pending_refund_reserves_overpayment_against_credit_transfer(): void
    {
        $source = $this->invoice();
        $target = $this->invoice();
        $payment = $this->finance->payment($source['id'], 20000, 'cash', 'Cash', 'overpaid-cash');
        $this->finance->refund($payment['id'], 3000, 'Returning overpayment', 'pending-overpayment-refund', 'pending');

        $this->assertSame(3000, $this->finance->balance($source)['pending_refund_minor']);
        $this->assertSame(762, $this->finance->balance($source)['overpayment_minor']);
        $this->assertRejected(fn () => $this->finance->transferCredit($source['id'], $target['id'], 3762, 'double-use-transfer'));
        $this->finance->transferCredit($source['id'], $target['id'], 762, 'remaining-transfer');
        $this->assertSame(0, $this->finance->balance($source)['overpayment_minor']);
        $this->assertCount(1, $this->records->all('credit_transfers'));
    }

    public function test_failed_provider_refund_releases_its_reservation_and_retries_under_the_same_key(): void
    {
        $this->enableStripe();
        $invoice = $this->invoice();
        $payment = $this->finance->payment($invoice['id'], 6000, 'stripe', 'cs_paid', 'stripe:cs_paid', ['payment_intent' => 'pi_refund']);
        Http::fake(['https://api.stripe.com/v1/refunds' => Http::sequence()->push(['error' => ['message' => 'Temporary failure']], 500)->push(['id' => 're_retry', 'status' => 'succeeded'])]);
        $request = ['amount_minor' => 2000, 'reason' => 'Refund requested', 'idempotency_key' => 'provider-refund-key'];

        $this->withSession(['auth.password_confirmed_at' => now()->timestamp])->postJson('/api/v1/payments/'.$payment['id'].'/refund', $request)->assertStatus(502);
        $refund = $this->records->all('refunds')[0];
        $this->assertSame('failed', $refund['status']);
        $this->assertSame(0, $this->finance->balance($invoice)['pending_refund_minor']);
        $this->assertSame(6000, $this->finance->balance($invoice)['paid_minor']);

        $this->withSession(['auth.password_confirmed_at' => now()->timestamp])->postJson('/api/v1/payments/'.$payment['id'].'/refund', $request)->assertOk()->assertJsonPath('data.status', 'settled');
        $this->assertCount(1, $this->records->all('refunds'));
        $this->assertSame('re_retry', $this->records->get('refunds', $refund['id'])['provider_id']);
        $this->assertSame(4000, $this->finance->balance($invoice)['paid_minor']);
        $keys = Http::recorded()->map(fn (array $pair) => $pair[0]->header('Idempotency-Key')[0] ?? null)->unique()->values()->all();
        $this->assertSame([$refund['id']], $keys);
    }

    public function test_paypal_refund_reported_before_the_refund_call_returns_is_counted_once(): void
    {
        $this->enablePaypal();
        $invoice = $this->invoice();
        $payment = $this->finance->payment($invoice['id'], 6000, 'paypal', 'CAPTURE1', 'paypal:CAPTURE1', ['capture_id' => 'CAPTURE1']);
        $event = ['id' => 'WH-REFUND-1', 'event_type' => 'PAYMENT.CAPTURE.REFUNDED', 'resource' => ['id' => 'REFUND1', 'status' => 'COMPLETED', 'amount' => ['value' => '20.00', 'currency_code' => 'USD'], 'links' => [['rel' => 'up', 'href' => 'https://api-m.sandbox.paypal.com/v2/payments/captures/CAPTURE1']]]];
        $this->fakePaypal(['*/v2/payments/captures/CAPTURE1/refund' => function () use ($event) {
            app(PaymentGateway::class)->paypalWebhook(Request::create('/api/v1/payments/webhooks/paypal', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($event, JSON_THROW_ON_ERROR)));

            return Http::response(['id' => 'REFUND1', 'status' => 'COMPLETED']);
        }]);

        $refund = app(PaymentGateway::class)->refund($payment, 2000, 'Internal note: goodwill after complaint', 'paypal-refund-key');

        $this->assertCount(1, $this->records->all('refunds'));
        $this->assertSame('settled', $refund['status']);
        $this->assertSame('REFUND1', $refund['provider_id']);
        $this->assertSame(4000, $this->finance->balance($invoice)['paid_minor']);
        Http::assertSent(fn (ClientRequest $request) => str_ends_with($request->url(), '/refund') && ! array_key_exists('note_to_payer', $request->data()) && ! str_contains($request->body(), 'Internal note') && $request['custom_id'] === $refund['id']);
    }

    public function test_stripe_refund_event_without_embedded_refunds_fetches_and_records_each_once(): void
    {
        $this->enableStripe();
        $invoice = $this->invoice();
        $payment = $this->finance->payment($invoice['id'], 6000, 'stripe', 'cs_two', 'stripe:cs_two', ['payment_intent' => 'pi_two']);
        $reservation = $this->finance->refund($payment['id'], 1000, 'Requested refund', 'app-refund-key', 'pending');
        Http::fake(['https://api.stripe.com/v1/refunds*' => Http::response(['object' => 'list', 'has_more' => false, 'data' => [
            ['id' => 're_app', 'status' => 'succeeded', 'amount' => 1000, 'currency' => 'usd', 'metadata' => ['local_refund_id' => $reservation['id']]],
            ['id' => 're_dashboard', 'status' => 'succeeded', 'amount' => 2500, 'currency' => 'usd', 'metadata' => []],
        ]])]);
        $charge = ['id' => 'ch_two', 'object' => 'charge', 'payment_intent' => 'pi_two', 'amount' => 6000, 'amount_refunded' => 3500, 'currency' => 'usd', 'metadata' => []];

        $this->stripeEvent(['id' => 'evt_refunded_one', 'type' => 'charge.refunded', 'data' => ['object' => $charge]])->assertOk();
        $this->stripeEvent(['id' => 'evt_refunded_two', 'type' => 'charge.refunded', 'data' => ['object' => $charge]])->assertOk();

        $this->assertCount(2, $this->records->all('refunds'));
        $this->assertSame(['settled', 're_app'], [$this->records->get('refunds', $reservation['id'])['status'], $this->records->get('refunds', $reservation['id'])['provider_id']]);
        $this->assertSame(2500, $this->finance->balance($invoice)['paid_minor']);
        Http::assertSent(fn (ClientRequest $request) => $request->method() === 'GET' && str_contains($request->url(), 'charge=ch_two'));
    }

    public function test_paypal_reversal_is_matched_through_its_related_capture(): void
    {
        $this->enablePaypal();
        $invoice = $this->invoice();
        $payment = $this->finance->payment($invoice['id'], 6000, 'paypal', 'CAPTURE2', 'paypal:CAPTURE2', ['capture_id' => 'CAPTURE2']);
        $this->fakePaypal();

        $this->postJson('/api/v1/payments/webhooks/paypal', ['id' => 'WH-REVERSED-1', 'event_type' => 'PAYMENT.CAPTURE.REVERSED', 'resource' => ['id' => 'REVERSAL1', 'amount' => ['value' => '60.00', 'currency_code' => 'USD'], 'links' => [['rel' => 'up', 'href' => 'https://api-m.sandbox.paypal.com/v2/payments/captures/CAPTURE2']]]])->assertOk();

        $this->assertSame($payment['id'], $this->records->get('reversals', 'paypal-REVERSAL1')['payment_id']);
        $this->assertSame(0, $this->finance->balance($invoice)['paid_minor']);
    }

    public function test_client_receives_only_customer_facing_finance_fields(): void
    {
        $invoice = $this->invoice();
        $payment = $this->finance->payment($invoice['id'], 6000, 'stripe', 'cs_client', 'stripe:cs_client', ['payment_intent' => 'pi_private', 'attempt_id' => 'attempt-private']);
        $this->finance->refund($payment['id'], 500, 'Internal: customer threatened a review', 'client-visible-refund', 'settled', 're_private');
        $this->finance->credit($invoice['id'], 1000, 'Goodwill', 'client-visible-credit');
        $estimate = $this->finance->draft('estimates', ['customer_id' => $this->customer['id'], 'title' => 'Repair', 'currency' => 'USD', 'items' => $invoice['items']]);
        $estimate = $this->finance->sendEstimate($estimate['id']);
        $this->getJson('/api/v1/invoices/'.$invoice['id'])->assertOk()->assertJsonPath('data.created_by', 'owner')->assertJsonPath('data.payments.0.payment_intent', 'pi_private');

        $this->actingAs(new CmsUser(['id' => 'client-own', 'role' => 'client', 'customer_id' => $this->customer['id'], 'email_verified_at' => now()->toISOString()]));
        $this->getJson('/api/v1/invoices')->assertOk()->assertJsonPath('data.0.balance_minor', 9738)->assertJsonMissingPath('data.0.created_by')->assertJsonMissingPath('data.0.snapshot')->assertJsonMissingPath('data.0.pdf_ref');
        $this->getJson('/api/v1/invoices/'.$invoice['id'])->assertOk()
            ->assertJsonPath('data.items.0.description', 'Labor')->assertJsonPath('data.paid_minor', 5500)
            ->assertJsonPath('data.payments.0.amount_minor', 6000)->assertJsonPath('data.payments.0.reference', 'cs_client')
            ->assertJsonMissingPath('data.snapshot')->assertJsonMissingPath('data.payments.0.payment_intent')->assertJsonMissingPath('data.payments.0.attempt_id')
            ->assertJsonMissingPath('data.payments.0.recorded_by')->assertJsonMissingPath('data.payments.0.snapshot')
            ->assertJsonMissingPath('data.refunds.0.reason')->assertJsonMissingPath('data.refunds.0.provider_id')->assertJsonMissingPath('data.credits.0.issued_by');
        $this->getJson('/api/v1/estimates/'.$estimate['id'])->assertOk()->assertJsonPath('data.version', $estimate['version'])->assertJsonMissingPath('data.created_by')->assertJsonMissingPath('data.snapshot');
    }

    public function test_recording_a_payment_expires_stale_stripe_checkout_without_dropping_a_late_payment(): void
    {
        $this->enableStripe();
        $invoice = $this->invoice();
        $stale = $this->records->create('payment_attempts', ['invoice_id' => $invoice['id'], 'customer_id' => $this->customer['id'], 'provider' => 'stripe', 'provider_id' => 'cs_stale', 'status' => 'ready', 'amount_minor' => 16238, 'currency' => 'USD', 'invoice_number' => $invoice['number'], 'expires_at' => now()->addMinutes(30)->toIso8601String()]);
        Http::fake([
            'https://api.stripe.com/v1/checkout/sessions/*/expire' => Http::response(['status' => 'expired']),
            'https://api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_fresh', 'url' => 'https://checkout.stripe.com/c/pay/cs_fresh']),
        ]);

        $this->postJson('/api/v1/invoices/'.$invoice['id'].'/payments', ['amount_minor' => 6000, 'method' => 'cash', 'reference' => 'Cash at door', 'idempotency_key' => 'cash-at-door'])->assertOk();
        $this->assertSame('expired', $this->records->get('payment_attempts', $stale['id'])['status']);
        Http::assertSent(fn (ClientRequest $request) => $request->url() === 'https://api.stripe.com/v1/checkout/sessions/cs_stale/expire');

        $this->postJson('/api/v1/invoices/'.$invoice['id'].'/checkout', ['provider' => 'stripe'])->assertOk()->assertJsonPath('data.url', 'https://checkout.stripe.com/c/pay/cs_fresh');
        $fresh = $this->records->all('payment_attempts', ['provider_id' => 'cs_fresh'])[0];
        $this->assertSame(['ready', 10238], [$fresh['status'], $fresh['amount_minor']]);

        $this->stripeEvent(['id' => 'evt_late_payment', 'type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_stale', 'object' => 'checkout.session', 'payment_status' => 'paid', 'currency' => 'usd', 'amount_total' => 16238, 'payment_intent' => 'pi_late', 'metadata' => ['invoice_id' => $invoice['id'], 'attempt_id' => $stale['id']]]]])->assertOk();
        $this->assertCount(2, $this->records->all('payments'));
        $this->assertSame(6000, $this->finance->balance($invoice)['overpayment_minor']);
        $this->assertSame('expired', $this->records->get('payment_attempts', $fresh['id'])['status']);
        Http::assertSent(fn (ClientRequest $request) => $request->url() === 'https://api.stripe.com/v1/checkout/sessions/cs_fresh/expire');
    }

    public function test_paypal_order_is_not_captured_once_it_exceeds_the_current_balance(): void
    {
        $this->enablePaypal();
        $invoice = $this->invoice();
        $attempt = $this->records->create('payment_attempts', ['invoice_id' => $invoice['id'], 'customer_id' => $this->customer['id'], 'provider' => 'paypal', 'provider_id' => 'ORDERSTALE', 'status' => 'ready', 'amount_minor' => 16238, 'currency' => 'USD', 'invoice_number' => $invoice['number'], 'expires_at' => now()->addMinutes(30)->toIso8601String()]);
        $this->finance->payment($invoice['id'], 6000, 'cash', 'Cash at door', 'cash-at-door');
        $this->fakePaypal(['*/v2/checkout/orders/ORDERSTALE/capture' => Http::response(['id' => 'ORDERSTALE', 'status' => 'COMPLETED'])]);

        $this->postJson('/api/v1/payments/webhooks/paypal', ['id' => 'WH-APPROVED-1', 'event_type' => 'CHECKOUT.ORDER.APPROVED', 'resource' => ['id' => 'ORDERSTALE', 'status' => 'APPROVED', 'purchase_units' => [['custom_id' => $attempt['id']]]]])->assertOk();

        Http::assertNotSent(fn (ClientRequest $request) => str_ends_with($request->url(), '/capture'));
        $this->assertSame('expired', $this->records->get('payment_attempts', $attempt['id'])['status']);
        $this->assertCount(1, $this->records->all('payments'));
    }

    public function test_stripe_events_for_checkouts_this_installation_did_not_start_are_acknowledged(): void
    {
        $this->enableStripe();
        $invoice = $this->invoice();
        $attempt = $this->records->create('payment_attempts', ['invoice_id' => $invoice['id'], 'provider' => 'stripe', 'provider_id' => 'cs_known', 'status' => 'ready', 'amount_minor' => 16238, 'currency' => 'USD']);
        Http::fake(['https://api.stripe.com/v1/payment_intents/pi_foreign' => Http::response(['id' => 'pi_foreign', 'metadata' => []])]);

        $this->stripeEvent(['id' => 'evt_foreign_session', 'type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_foreign', 'object' => 'checkout.session', 'payment_status' => 'paid', 'currency' => 'usd', 'amount_total' => 500, 'payment_intent' => 'pi_foreign', 'metadata' => []]]])->assertOk();
        $this->stripeEvent(['id' => 'evt_foreign_refund', 'type' => 'charge.refunded', 'data' => ['object' => ['id' => 'ch_foreign', 'object' => 'charge', 'payment_intent' => 'pi_foreign', 'currency' => 'usd', 'metadata' => []]]])->assertOk();
        $this->stripeEvent(['id' => 'evt_foreign_dispute', 'type' => 'charge.dispute.created', 'data' => ['object' => ['id' => 'dp_foreign', 'object' => 'dispute', 'payment_intent' => 'pi_foreign', 'amount' => 500, 'currency' => 'usd', 'status' => 'needs_response']]])->assertOk();
        $this->stripeEvent(['id' => 'evt_early_refund', 'type' => 'charge.refunded', 'data' => ['object' => ['id' => 'ch_known', 'object' => 'charge', 'payment_intent' => 'pi_known', 'currency' => 'usd', 'metadata' => ['attempt_id' => $attempt['id'], 'invoice_id' => $invoice['id']]]]])->assertConflict();

        $this->assertCount(0, $this->records->all('payments'));
        $this->assertCount(0, $this->records->all('disputes'));
        $this->assertNotNull($this->records->find('gateway_events', 'stripe-evt_foreign_dispute'));
        $this->assertNull($this->records->find('gateway_events', 'stripe-evt_early_refund'));
    }

    public function test_paypal_events_for_checkouts_this_installation_did_not_start_are_acknowledged(): void
    {
        $this->enablePaypal();
        $invoice = $this->invoice();
        $creating = $this->records->create('payment_attempts', ['invoice_id' => $invoice['id'], 'provider' => 'paypal', 'status' => 'creating', 'amount_minor' => 16238, 'currency' => 'USD']);
        $this->fakePaypal(['*/v2/payments/captures/CAPTUREFOREIGN' => Http::response(['name' => 'RESOURCE_NOT_FOUND'], 404)]);
        $refunded = ['id' => 'REFUNDFOREIGN', 'amount' => ['value' => '5.00', 'currency_code' => 'USD'], 'links' => [['rel' => 'up', 'href' => 'https://api-m.sandbox.paypal.com/v2/payments/captures/CAPTUREFOREIGN']]];

        $this->postJson('/api/v1/payments/webhooks/paypal', ['id' => 'WH-FOREIGN-1', 'event_type' => 'CHECKOUT.ORDER.APPROVED', 'resource' => ['id' => 'ORDERFOREIGN', 'purchase_units' => [['custom_id' => 'not-ours']]]])->assertOk();
        $this->postJson('/api/v1/payments/webhooks/paypal', ['id' => 'WH-FOREIGN-2', 'event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'resource' => ['id' => 'CAPTUREFOREIGN', 'amount' => ['value' => '5.00', 'currency_code' => 'USD'], 'supplementary_data' => ['related_ids' => ['order_id' => 'ORDERFOREIGN']]]])->assertOk();
        $this->postJson('/api/v1/payments/webhooks/paypal', ['id' => 'WH-FOREIGN-3', 'event_type' => 'PAYMENT.CAPTURE.REFUNDED', 'resource' => $refunded])->assertOk();
        $this->postJson('/api/v1/payments/webhooks/paypal', ['id' => 'WH-EARLY-1', 'event_type' => 'CHECKOUT.ORDER.APPROVED', 'resource' => ['id' => 'ORDERNEW', 'purchase_units' => [['custom_id' => $creating['id']]]]])->assertConflict();

        $this->assertCount(0, $this->records->all('payments'));
        $this->assertCount(0, $this->records->all('refunds'));
        $this->assertNotNull($this->records->find('gateway_events', 'paypal-WH-FOREIGN-3'));
        Http::assertNotSent(fn (ClientRequest $request) => str_ends_with($request->url(), '/capture'));
    }

    public function test_paypal_verification_sends_the_raw_event_and_reuses_the_access_token(): void
    {
        $this->enablePaypal();
        $this->fakePaypal();
        $first = '{"id":"WH-RAW-1","event_type":"CUSTOMER.PROFILE.UPDATED","resource":{"id":"R1","details":{},"tags":[]},"summary":"Path a/b"}';
        $second = '{"id":"WH-RAW-2","event_type":"CUSTOMER.PROFILE.UPDATED","resource":{"id":"R2","details":{}}}';

        foreach ([$first, $second] as $payload) {
            $this->call('POST', '/api/v1/payments/webhooks/paypal', [], [], [], ['CONTENT_TYPE' => 'application/json'], $payload)->assertOk();
        }

        Http::assertSent(fn (ClientRequest $request) => str_ends_with($request->url(), '/verify-webhook-signature') && str_ends_with($request->body(), '"webhook_event":'.$first.'}') && $request['webhook_id'] === 'test-webhook');
        $this->assertCount(1, Http::recorded(fn (ClientRequest $request) => str_ends_with($request->url(), '/v1/oauth2/token')));
        foreach (['payments.stripe.webhook', 'payments.paypal.webhook'] as $name) {
            $this->assertContains('throttle:120,1', Route::getRoutes()->getByName($name)->gatherMiddleware());
        }
    }
}

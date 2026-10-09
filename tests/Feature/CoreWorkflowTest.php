<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace Tests\Feature;

use App\Contracts\RecordRepository;
use App\Identity\CmsUser;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CoreWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private RecordRepository $records;

    private CmsUser $owner;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cms.data_provider' => 'postgres', 'cms.mfa_required' => false]);
        $this->records = app(RecordRepository::class);
        $this->owner = new CmsUser($this->records->create('users', ['name' => 'Office owner', 'email' => 'owner@example.test', 'password' => Hash::make('test-password-long'), 'role' => 'owner', 'active' => true, 'email_verified_at' => now()->toISOString()]));
        Settings::put('business', ['name' => 'Test Trades', 'legal_name' => 'Test Trades LLC', 'currency' => 'USD', 'country' => 'US']);
    }

    public function test_inquiry_to_client_approved_job_invoice_and_settled_payment(): void
    {
        $this->postJson('/api/v1/public/leads', ['name' => 'Avery', 'email' => 'avery@example.test', 'service' => 'Plumbing', 'message' => 'Please arrange a service assessment.', 'marketing_consent' => false])->assertCreated();
        $this->assertSame('Please arrange a service assessment.', $this->records->all('leads')[0]['notes']);
        $this->actingAs($this->owner);
        $customer = $this->postJson('/api/v1/customers', ['name' => 'Avery', 'email' => 'avery@example.test', 'country' => 'US'])->assertCreated()->json('data');
        $input = ['customer_id' => $customer['id'], 'title' => 'Service assessment', 'currency' => 'USD', 'items' => [['description' => 'Assessment visit', 'quantity' => '1.5', 'unit_price_minor' => 10000, 'tax_basis_points' => 0]]];
        $estimate = $this->postJson('/api/v1/estimates', $input)->assertCreated()->json('data');
        $sent = $this->postJson('/api/v1/estimates/'.$estimate['id'].'/send')->assertOk()->json('data');
        $client = new CmsUser($this->records->create('users', ['name' => 'Avery', 'email' => 'client@example.test', 'role' => 'client', 'customer_id' => $customer['id'], 'email_verified_at' => now()->toISOString(), 'active' => true]));
        $this->actingAs($client);
        $this->postJson('/api/v1/estimates/'.$estimate['id'].'/accept', ['version' => $sent['version']])->assertOk();
        $this->postJson('/api/v1/estimates/'.$estimate['id'].'/accept', ['version' => $sent['version']])->assertOk();
        $this->assertCount(1, $this->records->all('jobs'));
        $job = $this->records->all('jobs')[0];
        $this->actingAs($this->owner);
        $tech = $this->records->create('users', ['name' => 'Technician', 'role' => 'technician', 'email' => 'tech@example.test', 'active' => true]);
        $this->patchJson('/api/v1/jobs/'.$job['id'], ['status' => 'scheduled', 'assigned_to' => [$tech['id']], 'scheduled_at' => now()->addDay()->toISOString(), 'duration_minutes' => 60])->assertOk();
        $this->actingAs(new CmsUser($tech));
        $this->patchJson('/api/v1/jobs/'.$job['id'], ['status' => 'completed', 'field_notes' => 'Visit completed.'])->assertOk()->assertJsonPath('data.completion_requested', true)->assertJsonPath('data.status', 'in_progress');
        $this->actingAs($this->owner);
        $this->patchJson('/api/v1/jobs/'.$job['id'], ['status' => 'completed'])->assertOk();
        $invoice = $this->postJson('/api/v1/invoices', $input + ['job_id' => $job['id']])->assertCreated()->json('data');
        $this->postJson('/api/v1/invoices/'.$invoice['id'].'/issue')->assertOk();
        $payment = ['amount_minor' => 15000, 'method' => 'cheque', 'reference' => 'TEST-001', 'idempotency_key' => 'core-flow-payment'];
        $this->postJson('/api/v1/invoices/'.$invoice['id'].'/payments', $payment)->assertOk();
        $this->postJson('/api/v1/invoices/'.$invoice['id'].'/payments', $payment)->assertOk();
        $this->assertCount(1, $this->records->all('payments'));
        $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('data.metrics.collected_minor', 15000)->assertJsonPath('data.metrics.outstanding_minor', 0);
        $this->actingAs($client);
        $this->getJson('/api/v1/invoices/'.$invoice['id'])->assertOk()->assertJsonPath('data.payment_status', 'paid');
        $this->get('/api/v1/invoices/'.$invoice['id'].'/pdf')->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_overlapping_assignments_are_rejected_and_adjacent_visits_allowed(): void
    {
        $this->actingAs($this->owner);
        $customer = $this->records->create('customers', ['name' => 'Customer']);
        $tech = $this->records->create('users', ['name' => 'Tech', 'role' => 'technician', 'active' => true]);
        $input = ['title' => 'First visit', 'customer_id' => $customer['id'], 'assigned_to' => [$tech['id']], 'scheduled_at' => '2027-01-15T10:00:00Z', 'duration_minutes' => 60, 'status' => 'scheduled'];
        $this->postJson('/api/v1/jobs', $input)->assertCreated();
        $this->postJson('/api/v1/jobs', array_replace($input, ['scheduled_at' => '2027-01-15T10:30:00Z']))->assertConflict();
        $this->postJson('/api/v1/jobs', array_replace($input, ['scheduled_at' => '2027-01-15T11:00:00Z']))->assertCreated();
    }

    public function test_optimistic_versions_prevent_lost_updates(): void
    {
        $this->actingAs($this->owner);
        $lead = $this->postJson('/api/v1/leads', ['name' => 'Avery'])->json('data');
        $this->patchJson('/api/v1/leads/'.$lead['id'], ['expected_version' => 1, 'stage' => 'contacted'])->assertOk();
        $this->patchJson('/api/v1/leads/'.$lead['id'], ['expected_version' => 1, 'stage' => 'lost'])->assertConflict();
    }
}

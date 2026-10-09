<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace Tests\Feature;

use App\Contracts\RecordRepository;
use App\Identity\CmsUser;
use App\Operations\OperationsWorkflow;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class OperationsWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private RecordRepository $records;

    private CmsUser $owner;

    private CmsUser $technician;

    private CmsUser $client;

    private array $customer;

    private array $job;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cms.data_provider' => 'postgres', 'cms.mfa_required' => false]);
        if (! Route::has('properties.index')) {
            Route::middleware('web')->group(base_path('routes/operations.php'));
        }
        $this->records = app(RecordRepository::class);
        $this->owner = new CmsUser($this->records->create('users', ['role' => 'owner', 'name' => 'Owner', 'email' => 'owner@example.test', 'active' => true]));
        $this->technician = new CmsUser($this->records->create('users', ['role' => 'technician', 'name' => 'Tech', 'email' => 'tech@example.test', 'active' => true]));
        $this->customer = $this->records->create('customers', ['name' => 'Client', 'email' => 'client@example.test']);
        $this->client = new CmsUser($this->records->create('users', ['role' => 'client', 'name' => 'Client', 'email' => 'client@example.test', 'customer_id' => $this->customer['id'], 'email_verified_at' => now()->toISOString(), 'active' => true]));
        $this->job = $this->records->create('jobs', ['title' => 'Repair', 'customer_id' => $this->customer['id'], 'status' => 'scheduled', 'assigned_to' => [$this->technician->id], 'estimated_total_minor' => 20000, 'currency' => 'USD']);
        Settings::put('business', ['legal_name' => 'Plumbing Company', 'currency' => 'USD']);
        $this->actingAs($this->owner);
    }

    private function requestVisit(?string $jobId = null): array
    {
        return $this->postJson('/api/v1/visits', ['job_id' => $jobId ?? $this->job['id'], 'start_at' => now()->addDays(2)->setTime(14, 0)->toISOString(), 'duration_minutes' => 60, 'purpose' => 'Inspect reported drain issue', 'assigned_to' => [$this->technician->id]])->assertCreated()->json('data');
    }

    private function sentChange(int $amount = 5000): array
    {
        $change = $this->postJson('/api/v1/change-orders', ['job_id' => $this->job['id'], 'title' => 'Replace additional section', 'description' => 'Replace the damaged branch discovered during access.', 'amount_minor' => $amount, 'currency' => 'USD'])->assertCreated()->json('data');

        return $this->postJson('/api/v1/change-orders/'.$change['id'].'/send', ['version' => $change['version']])->assertOk()->json('data');
    }

    public function test_client_properties_are_scoped_and_internal_notes_are_hidden(): void
    {
        $property = $this->postJson('/api/v1/properties', ['customer_id' => $this->customer['id'], 'name' => 'Home', 'address' => '1 Test Street', 'country' => 'US', 'internal_notes' => 'Private office note'])->assertCreated()->json('data');
        $this->actingAs($this->client);
        $this->getJson('/api/v1/properties')->assertOk()->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.internal_notes');
        $this->putJson('/api/v1/properties/'.$property['id'], ['version' => $property['version'], 'access_notes' => 'Use side gate'])->assertOk()->assertJsonPath('data.access_notes', 'Use side gate');
        $other = new CmsUser(['id' => 'other-client', 'role' => 'client', 'customer_id' => 'other-customer', 'email_verified_at' => now()->toISOString()]);
        $this->actingAs($other)->getJson('/api/v1/properties/'.$property['id'])->assertForbidden();
        $this->getJson('/api/v1/properties?customer_id='.$this->customer['id'])->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_property_cannot_be_attached_to_another_customer_job(): void
    {
        $other = $this->records->create('customers', ['name' => 'Other']);
        $property = $this->records->create('properties', ['customer_id' => $other['id'], 'name' => 'Other home', 'address' => '2 Test Street', 'status' => 'active']);
        $this->patchJson('/api/v1/jobs/'.$this->job['id'], ['property_id' => $property['id']])->assertUnprocessable();
        $this->patchJson('/api/v1/jobs/'.$this->job['id'], ['customer_id' => $other['id']])->assertUnprocessable();
    }

    public function test_requested_visits_do_not_reserve_capacity_and_confirmation_detects_overlap(): void
    {
        $first = $this->requestVisit();
        $second = $this->requestVisit();
        $this->assertSame('requested', $first['status']);
        $confirmed = $this->postJson('/api/v1/visits/'.$first['id'].'/confirm', ['version' => $first['version']])->assertOk()->assertJsonPath('data.status', 'confirmed')->json('data');
        $this->postJson('/api/v1/visits/'.$second['id'].'/confirm', ['version' => $second['version']])->assertConflict();
        $this->postJson('/api/v1/visits/'.$first['id'].'/confirm', ['version' => $first['version']])->assertOk()->assertJsonPath('data.id', $confirmed['id']);
        $this->assertSame('requested', $this->records->get('visits', $second['id'])['status']);
    }

    public function test_visits_and_legacy_job_schedule_share_conflict_protection(): void
    {
        $visit = $this->requestVisit();
        $this->postJson('/api/v1/visits/'.$visit['id'].'/confirm', ['version' => $visit['version']])->assertOk();
        $this->postJson('/api/v1/jobs', ['title' => 'Overlapping job', 'customer_id' => $this->customer['id'], 'assigned_to' => [$this->technician->id], 'scheduled_at' => $visit['start_at'], 'duration_minutes' => 60])->assertConflict();
        $legacy = $this->records->create('jobs', ['title' => 'Legacy job', 'customer_id' => $this->customer['id'], 'status' => 'scheduled', 'assigned_to' => [$this->technician->id], 'scheduled_at' => now()->addDays(3)->setTime(14, 0)->toISOString(), 'duration_minutes' => 60]);
        $requested = $this->postJson('/api/v1/visits', ['job_id' => $this->job['id'], 'start_at' => $legacy['scheduled_at'], 'duration_minutes' => 60, 'purpose' => 'Second visit', 'assigned_to' => [$this->technician->id]])->assertCreated()->json('data');
        $this->postJson('/api/v1/visits/'.$requested['id'].'/confirm', ['version' => $requested['version']])->assertConflict();
    }

    public function test_client_can_request_but_cannot_confirm_or_cancel_confirmed_visit(): void
    {
        $this->actingAs($this->client);
        $visit = $this->requestVisit();
        $this->postJson('/api/v1/visits/'.$visit['id'].'/confirm', ['version' => $visit['version']])->assertForbidden();
        $this->actingAs($this->owner);
        $confirmed = $this->postJson('/api/v1/visits/'.$visit['id'].'/confirm', ['version' => $visit['version']])->assertOk()->json('data');
        $this->actingAs($this->client)->postJson('/api/v1/visits/'.$visit['id'].'/cancel', ['version' => $confirmed['version']])->assertForbidden();
    }

    public function test_confirmation_queues_frozen_appointment_template_for_verified_client(): void
    {
        Settings::put('notifications', ['email_enabled' => true, 'digest' => 'instant']);
        $visit = $this->requestVisit();
        $this->postJson('/api/v1/visits/'.$visit['id'].'/confirm', ['version' => $visit['version']])->assertOk();
        $messages = $this->records->all('outbox', ['type' => 'email']);
        $this->assertCount(1, $messages);
        $payload = $messages[0]['payload'];
        $this->assertSame($this->client->email, $payload['to']);
        $this->assertSame('appointment', $payload['template_key']);
        $this->assertStringContainsString('Repair', $payload['body']);
        $this->assertStringContainsString('UTC', $payload['body']);
        $this->assertStringContainsString('/app/jobs', $payload['body']);
        $this->assertArrayNotHasKey('template_data', $payload);
        $this->postJson('/api/v1/visits/'.$visit['id'].'/confirm', ['version' => $visit['version']])->assertOk();
        $this->assertCount(1, $this->records->all('outbox', ['type' => 'email']));
    }

    public function test_change_order_is_immutable_when_sent_and_requires_exact_client_approval_once(): void
    {
        $change = $this->sentChange();
        $this->putJson('/api/v1/change-orders/'.$change['id'], ['version' => $change['version'], 'title' => 'Changed', 'description' => 'Changed description', 'amount_minor' => 100])->assertUnprocessable();
        $this->actingAs($this->client);
        $this->postJson('/api/v1/change-orders/'.$change['id'].'/accept', ['version' => $change['version'] - 1])->assertUnprocessable();
        $this->postJson('/api/v1/change-orders/'.$change['id'].'/accept', ['version' => $change['version']])->assertOk()->assertJsonPath('data.acceptance_source', 'client_portal');
        $this->postJson('/api/v1/change-orders/'.$change['id'].'/accept', ['version' => $change['version']])->assertOk();
        $job = $this->records->get('jobs', $this->job['id']);
        $this->assertCount(1, $job['approved_changes']);
        $this->assertSame(25000, $job['approved_total_minor']);
        $this->getJson('/api/v1/jobs/'.$this->job['id'])->assertOk()->assertJsonCount(1, 'data.approved_changes');
    }

    public function test_foreign_clients_cannot_approve_and_rejected_changes_do_not_alter_scope(): void
    {
        $change = $this->sentChange();
        $foreign = new CmsUser(['id' => 'foreign-client', 'role' => 'client', 'customer_id' => 'foreign-customer', 'email_verified_at' => now()->toISOString()]);
        $this->actingAs($foreign)->postJson('/api/v1/change-orders/'.$change['id'].'/accept', ['version' => $change['version']])->assertForbidden();
        $this->getJson('/api/v1/change-orders?job_id='.$this->job['id'])->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->client)->postJson('/api/v1/change-orders/'.$change['id'].'/reject', ['version' => $change['version'], 'reason' => 'Please discuss alternatives'])->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->assertArrayNotHasKey('approved_changes', $this->records->get('jobs', $this->job['id']));
    }

    public function test_negative_change_cannot_exceed_existing_contract_total(): void
    {
        $change = $this->sentChange(-20001);
        $this->actingAs($this->client)->postJson('/api/v1/change-orders/'.$change['id'].'/accept', ['version' => $change['version']])->assertUnprocessable();
        $this->assertSame('sent', $this->records->get('change_orders', $change['id'])['status']);
    }

    public function test_technician_can_only_read_approved_changes_for_assigned_jobs(): void
    {
        $change = $this->sentChange();
        $this->actingAs($this->technician)->getJson('/api/v1/change-orders')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/change-orders/'.$change['id'])->assertForbidden();
        app(OperationsWorkflow::class)->acceptChange($change['id'], $change['version'], $this->client->id, '127.0.0.1');
        $this->getJson('/api/v1/change-orders')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/api/v1/change-orders/'.$change['id'].'/cancel', ['version' => $change['version'] + 1])->assertForbidden();
    }
}

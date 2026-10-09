<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace Tests\Feature;

use App\Contracts\RecordRepository;
use App\Identity\CmsUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrmPlanningTest extends TestCase
{
    use RefreshDatabase;

    private RecordRepository $records;

    private CmsUser $owner;

    private CmsUser $technician;

    private array $customer;

    private array $job;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cms.data_provider' => 'postgres', 'cms.mfa_required' => false]);
        $this->records = app(RecordRepository::class);
        $this->owner = $this->user('owner');
        $this->technician = $this->user('technician');
        $this->customer = $this->records->create('customers', ['name' => 'Casey']);
        $this->job = $this->records->create('jobs', ['title' => 'Site assessment', 'customer_id' => $this->customer['id'], 'assigned_to' => [$this->technician->id]]);
        $this->actingAs($this->owner);
    }

    private function user(string $role): CmsUser
    {
        return new CmsUser($this->records->create('users', ['name' => ucfirst($role), 'email' => $role.'@example.test', 'role' => $role, 'active' => true, 'email_verified_at' => now()->toISOString()]));
    }

    private function task(array $overrides = []): array
    {
        return $this->postJson('/api/v1/tasks', array_replace(['title' => 'Confirm property access', 'job_id' => $this->job['id'], 'customer_id' => $this->customer['id'], 'assigned_to' => [$this->technician->id], 'due_at' => '2027-03-05', 'priority' => 'high', 'checklist' => [['label' => 'Call the customer', 'done' => false]]], $overrides))->assertCreated()->json('data');
    }

    private function note(array $overrides = []): array
    {
        return $this->postJson('/api/v1/crm-notes', array_replace(['title' => 'Access arrangements', 'body' => 'Call before arrival. The customer will open the side gate.', 'note_date' => '2027-03-05', 'customer_id' => $this->customer['id'], 'job_id' => $this->job['id']], $overrides))->assertCreated()->json('data');
    }

    public function test_task_board_persists_stages_checklist_and_due_date_with_audit(): void
    {
        $task = $this->task();
        $this->assertSame('open', $task['status']);
        $this->getJson('/api/v1/tasks/'.$task['id'])->assertOk()->assertJsonPath('data.due_at', '2027-03-05');
        foreach (['in_progress', 'blocked', 'done'] as $status) {
            $task = $this->patchJson('/api/v1/tasks/'.$task['id'], ['status' => $status, 'expected_version' => $task['version'], 'checklist' => [['label' => 'Call the customer', 'done' => $status === 'done']]])->assertOk()->assertJsonPath('data.status', $status)->json('data');
        }
        $this->assertTrue($this->records->get('tasks', $task['id'])['checklist'][0]['done']);
        $this->assertCount(4, $this->records->all('audit'));
    }

    public function test_task_updates_require_current_version_and_reject_stale_board_moves(): void
    {
        $task = $this->task();
        $this->patchJson('/api/v1/tasks/'.$task['id'], ['status' => 'done'])->assertUnprocessable()->assertJsonValidationErrors('expected_version');
        $this->patchJson('/api/v1/tasks/'.$task['id'], ['status' => 'in_progress', 'expected_version' => 1])->assertOk();
        $this->patchJson('/api/v1/tasks/'.$task['id'], ['status' => 'done', 'expected_version' => 1])->assertConflict();
        $this->assertSame('in_progress', $this->records->get('tasks', $task['id'])['status']);
        $this->assertCount(2, $this->records->all('audit'));
    }

    public function test_technicians_can_only_update_progress_on_their_assigned_tasks(): void
    {
        $assigned = $this->task();
        $foreign = $this->task(['assigned_to' => []]);
        $this->actingAs($this->technician);
        $this->getJson('/api/v1/tasks')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/tasks/'.$foreign['id'])->assertForbidden();
        $this->patchJson('/api/v1/tasks/'.$foreign['id'], ['status' => 'done', 'expected_version' => 1])->assertForbidden();
        $this->postJson('/api/v1/tasks', ['title' => 'Unauthorized'])->assertForbidden();
        $this->patchJson('/api/v1/tasks/'.$assigned['id'], ['assigned_to' => [$this->owner->id], 'expected_version' => 1])->assertUnprocessable();
        $this->patchJson('/api/v1/tasks/'.$assigned['id'], ['assigned_to' => [], 'expected_version' => 1])->assertUnprocessable();
        $this->patchJson('/api/v1/tasks/'.$assigned['id'], ['customer_id' => null, 'expected_version' => 1])->assertUnprocessable();
        $this->patchJson('/api/v1/tasks/'.$assigned['id'], ['status' => 'in_progress', 'notes' => 'Waiting for a callback.', 'checklist' => [['label' => 'Call the customer', 'done' => true]], 'expected_version' => 1])->assertOk()->assertJsonPath('data.notes', 'Waiting for a callback.');
        $this->assertSame([$this->technician->id], $this->records->get('tasks', $assigned['id'])['assigned_to']);
    }

    public function test_task_assignments_and_relations_cannot_reference_invalid_or_unrelated_records(): void
    {
        $foreignCustomer = $this->records->create('customers', ['name' => 'Other customer']);
        $this->postJson('/api/v1/tasks', ['title' => 'Wrong customer', 'customer_id' => $foreignCustomer['id'], 'job_id' => $this->job['id']])->assertUnprocessable()->assertJsonValidationErrors('job_id');
        $this->postJson('/api/v1/tasks', ['title' => 'Missing job', 'job_id' => 'missing-job'])->assertUnprocessable();
        $this->postJson('/api/v1/tasks', ['title' => 'Missing user', 'assigned_to' => ['missing-user']])->assertUnprocessable();
        $client = $this->user('client');
        $this->postJson('/api/v1/tasks', ['title' => 'Client assignment', 'assigned_to' => [$client->id]])->assertUnprocessable();
        $inactive = $this->records->create('users', ['role' => 'technician', 'active' => false]);
        $this->postJson('/api/v1/tasks', ['title' => 'Inactive assignment', 'assigned_to' => [$inactive['id']]])->assertUnprocessable();
        $task = $this->task();
        $this->patchJson('/api/v1/tasks/'.$task['id'], ['customer_id' => $foreignCustomer['id'], 'expected_version' => 1])->assertUnprocessable();
        $this->assertSame($this->customer['id'], $this->records->get('tasks', $task['id'])['customer_id']);
    }

    public function test_crm_notes_are_dated_filterable_and_attributed_to_the_authenticated_author(): void
    {
        $note = $this->note(['created_by' => 'forged-author']);
        $this->note(['note_date' => '2027-04-02', 'title' => 'Later call']);
        $this->assertSame($this->owner->id, $note['created_by']);
        $this->getJson('/api/v1/crm-notes?from=2027-03-01&to=2027-03-31&customer_id='.$this->customer['id'])->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $note['id']);
        $this->getJson('/api/v1/crm-notes/'.$note['id'])->assertOk()->assertJsonPath('data.body', $note['body']);
        $updated = $this->patchJson('/api/v1/crm-notes/'.$note['id'], ['body' => 'Access has been confirmed.', 'expected_version' => $note['version']])->assertOk()->json('data');
        $this->assertSame($this->owner->id, $updated['created_by']);
        $this->assertSame('Access has been confirmed.', $this->records->get('crm_notes', $note['id'])['body']);
        $this->assertCount(3, $this->records->all('audit'));
    }

    public function test_stale_notes_cannot_overwrite_current_body_or_add_an_audit_entry(): void
    {
        $note = $this->note();
        $this->patchJson('/api/v1/crm-notes/'.$note['id'], ['body' => 'Missing version'])->assertUnprocessable()->assertJsonValidationErrors('expected_version');
        $this->patchJson('/api/v1/crm-notes/'.$note['id'], ['body' => 'First update', 'expected_version' => 1])->assertOk();
        $this->patchJson('/api/v1/crm-notes/'.$note['id'], ['body' => 'Stale update', 'expected_version' => 1])->assertConflict();
        $this->assertSame('First update', $this->records->get('crm_notes', $note['id'])['body']);
        $this->assertCount(2, $this->records->all('audit'));
    }

    public function test_note_relations_are_validated_against_existing_links_when_partially_updated(): void
    {
        $otherCustomer = $this->records->create('customers', ['name' => 'Other']);
        $otherJob = $this->records->create('jobs', ['title' => 'Other job', 'customer_id' => $otherCustomer['id']]);
        $linkedLead = $this->records->create('leads', ['name' => 'Casey', 'customer_id' => $this->customer['id']]);
        $unrelatedLead = $this->records->create('leads', ['name' => 'Unrelated']);
        $note = $this->note(['lead_id' => $linkedLead['id']]);
        $this->patchJson('/api/v1/crm-notes/'.$note['id'], ['job_id' => $otherJob['id'], 'expected_version' => 1])->assertUnprocessable()->assertJsonValidationErrors('job_id');
        $this->patchJson('/api/v1/crm-notes/'.$note['id'], ['lead_id' => $unrelatedLead['id'], 'expected_version' => 1])->assertUnprocessable()->assertJsonValidationErrors('lead_id');
        $this->postJson('/api/v1/crm-notes', ['title' => 'Missing relation', 'body' => 'Text', 'note_date' => '2027-03-01', 'job_id' => 'missing'])->assertUnprocessable();
        $this->assertSame($this->job['id'], $this->records->get('crm_notes', $note['id'])['job_id']);
    }

    public function test_clients_and_technicians_never_receive_internal_crm_notes(): void
    {
        $note = $this->note();
        $task = $this->task();
        foreach ([$this->user('client'), $this->technician] as $user) {
            $this->actingAs($user);
            $this->getJson('/api/v1/crm-notes')->assertForbidden();
            $this->getJson('/api/v1/crm-notes/'.$note['id'])->assertForbidden();
            $this->postJson('/api/v1/crm-notes', ['title' => 'Hidden'])->assertForbidden();
            $this->patchJson('/api/v1/crm-notes/'.$note['id'], ['body' => 'Changed', 'expected_version' => 1])->assertForbidden();
            if ($user->role === 'client') {
                $this->getJson('/api/v1/tasks')->assertForbidden();
                $this->patchJson('/api/v1/tasks/'.$task['id'], ['status' => 'done', 'expected_version' => 1])->assertForbidden();
            }
        }
    }

    public function test_staff_read_permissions_do_not_grant_task_or_note_write_access(): void
    {
        $note = $this->note();
        $task = $this->task();
        foreach (['finance', 'marketing'] as $role) {
            $this->actingAs($this->user($role));
            $this->getJson('/api/v1/crm-notes')->assertOk();
            $this->getJson('/api/v1/tasks')->assertOk();
            $this->patchJson('/api/v1/tasks/'.$task['id'], ['status' => 'done', 'expected_version' => 1])->assertForbidden();
            $this->patchJson('/api/v1/crm-notes/'.$note['id'], ['body' => 'Changed', 'expected_version' => 1])->assertForbidden();
        }
        $this->actingAs($this->user('estimator'));
        $this->task(['assigned_to' => []]);
        $this->note();
        $this->actingAs($this->user('dispatcher'));
        $this->task(['assigned_to' => []]);
        $this->note();
    }

    public function test_planning_inputs_reject_invalid_dates_status_and_malformed_checklists(): void
    {
        $this->postJson('/api/v1/tasks', ['title' => 'Invalid status', 'status' => 'deleted'])->assertUnprocessable();
        $this->postJson('/api/v1/tasks', ['title' => 'Invalid date', 'due_at' => '2200-01-01'])->assertUnprocessable();
        $this->postJson('/api/v1/tasks', ['title' => 'Invalid checklist', 'checklist' => [['label' => 'Call']]])->assertUnprocessable();
        $this->postJson('/api/v1/crm-notes', ['title' => 'Invalid date', 'body' => 'Text', 'note_date' => '2027-02-30'])->assertUnprocessable();
        $this->postJson('/api/v1/crm-notes', ['title' => 'Invalid date', 'body' => 'Text', 'note_date' => '2200-01-01'])->assertUnprocessable();
        $this->getJson('/api/v1/crm-notes?from=2027-04-01&to=2027-03-01')->assertUnprocessable();
    }
}

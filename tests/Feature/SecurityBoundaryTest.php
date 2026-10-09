<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace Tests\Feature;

use App\Contracts\RecordRepository;
use App\Identity\CmsUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SecurityBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private RecordRepository $records;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cms.data_provider' => 'postgres', 'cms.mfa_required' => false]);
        $this->records = app(RecordRepository::class);
    }

    public function test_registration_cannot_set_role_or_claim_existing_customer(): void
    {
        $existing = $this->records->create('customers', ['name' => 'Existing customer', 'email' => 'avery@example.test']);
        $form = ['name' => 'Avery', 'email' => 'avery@example.test', 'password' => 'long-password-123', 'password_confirmation' => 'long-password-123'];
        $this->postJson('/register', $form + ['role' => 'owner', 'customer_id' => $existing['id']])->assertUnprocessable();
        $this->post('/register', $form)->assertRedirect('/app');
        $user = $this->records->all('users')[0];
        $this->assertSame('client', $user['role']);
        $this->assertNotSame($existing['id'], $user['customer_id']);
        $this->getJson('/api/v1/jobs')->assertForbidden();
        $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonCount(0, 'data.jobs');
    }

    public function test_login_and_logout_without_remember_token(): void
    {
        $this->records->create('users', ['name' => 'Owner', 'email' => 'owner@example.test', 'password' => Hash::make('valid-password-123'), 'role' => 'owner', 'active' => true]);
        $this->post('/login', ['email' => 'owner@example.test', 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->post('/login', ['email' => 'owner@example.test', 'password' => 'valid-password-123'])->assertRedirect('/app');
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_private_file_download_requires_relationship_and_quarantine_is_enforced(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('uploads/test.txt', 'private');
        $client = new CmsUser(['id' => 'client', 'role' => 'client', 'customer_id' => 'c1', 'email_verified_at' => now()->toISOString()]);
        $file = $this->records->create('files', ['owner_id' => 'someone-else', 'visibility' => 'private', 'status' => 'ready', 'path' => 'uploads/test.txt', 'name' => 'test.txt', 'mime' => 'text/plain']);
        $this->actingAs($client)->get('/files/'.$file['id'])->assertForbidden();
        $this->records->update('files', $file['id'], ['owner_id' => 'client', 'status' => 'quarantined']);
        $this->get('/files/'.$file['id'])->assertStatus(423);
    }

    public function test_clients_cannot_publish_or_read_credentials_or_change_team(): void
    {
        $this->actingAs(new CmsUser(['id' => 'client', 'role' => 'client', 'email_verified_at' => now()->toISOString()]));
        $this->getJson('/api/v1/settings/payments')->assertForbidden();
        $this->postJson('/api/v1/pages', ['title' => 'Attempt'])->assertForbidden();
        $this->postJson('/api/v1/invitations', ['email' => 'attack@example.test', 'role' => 'owner'])->assertForbidden();
        $this->postJson('/api/v1/invoices', [])->assertForbidden();
        $this->getJson('/api/v1/payroll/runs')->assertForbidden();
    }

    public function test_clients_cannot_join_internal_chat_and_messages_are_idempotent(): void
    {
        $owner = $this->records->create('users', ['name' => 'Owner', 'role' => 'owner', 'email' => 'owner@example.test']);
        $staff = $this->records->create('users', ['name' => 'Tech', 'role' => 'technician', 'email' => 'staff@example.test']);
        $client = $this->records->create('users', ['name' => 'Client', 'role' => 'client', 'customer_id' => 'c1', 'email_verified_at' => now()->toISOString()]);
        $this->actingAs(new CmsUser($owner));
        $this->postJson('/api/v1/conversations', ['title' => 'Private', 'type' => 'internal', 'participants' => [$client['id']]])->assertUnprocessable();
        $chat = $this->postJson('/api/v1/conversations', ['title' => 'Crew', 'type' => 'internal', 'participants' => [$staff['id']]])->assertCreated()->json('data');
        $message = ['body' => 'Assigned scope attached', 'idempotency_key' => 'same-message-key'];
        $this->postJson('/api/v1/conversations/'.$chat['id'].'/messages', $message)->assertCreated();
        $this->postJson('/api/v1/conversations/'.$chat['id'].'/messages', $message)->assertCreated();
        $this->assertCount(1, $this->records->all('messages'));
        $this->assertCount(1, $this->records->all('notifications', ['user_id' => $staff['id']]));
        $this->actingAs(new CmsUser($client))->getJson('/api/v1/conversations/'.$chat['id'].'/messages')->assertForbidden();
    }
}

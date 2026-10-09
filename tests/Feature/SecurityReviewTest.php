<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace Tests\Feature;

use App\Contracts\RecordRepository;
use App\Finance\FinanceService;
use App\Identity\CmsUser;
use App\Infrastructure\BackupService;
use App\Support\Settings;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class SecurityReviewTest extends TestCase
{
    use RefreshDatabase;

    private RecordRepository $records;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cms.data_provider' => 'postgres', 'cms.mfa_required' => false]);
        $this->records = app(RecordRepository::class);
    }

    private function user(string $role, array $extra = []): CmsUser
    {
        return new CmsUser($this->records->create('users', array_merge(['name' => ucfirst($role), 'email' => Str::uuid().'@example.test', 'role' => $role, 'password' => Hash::make('Strong password here'), 'active' => true, 'email_verified_at' => now()->toISOString()], $extra)));
    }

    public function test_client_jobs_and_dashboard_hide_internal_fields_and_financial_snapshots_hide_crm_notes(): void
    {
        $customer = $this->records->create('customers', ['name' => 'Customer', 'email' => 'customer@example.test', 'notes' => 'Private credit concerns']);
        $job = $this->records->create('jobs', ['customer_id' => $customer['id'], 'title' => 'Repair', 'status' => 'scheduled', 'assigned_to' => [], 'notes' => 'Private office note', 'field_notes' => 'Internal access note', 'actual_cost_minor' => 7500, 'checklist' => [['label' => 'Internal review', 'done' => false]], 'scheduled_at' => now()->addDay()->toISOString()]);
        $this->actingAs($this->user('client', ['customer_id' => $customer['id']]));
        $this->getJson('/api/v1/jobs/'.$job['id'])->assertOk()->assertJsonMissingPath('data.notes')->assertJsonMissingPath('data.field_notes')->assertJsonMissingPath('data.actual_cost_minor')->assertJsonMissingPath('data.checklist')->assertJsonPath('data.title', 'Repair');
        $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonMissingPath('data.jobs.0.field_notes')->assertJsonMissingPath('data.jobs.0.actual_cost_minor');
        Settings::put('business', ['legal_name' => 'Test Business']);
        $finance = app(FinanceService::class);
        $invoice = $finance->draft('invoices', ['customer_id' => $customer['id'], 'title' => 'Invoice', 'currency' => 'USD', 'items' => [['description' => 'Repair', 'quantity' => '1', 'unit_price_minor' => 15000]]]);
        $invoice = $finance->issue($invoice['id']);
        $this->assertArrayNotHasKey('notes', $invoice['snapshot']['customer']);
    }

    public function test_office_and_custom_finance_permissions_require_mfa(): void
    {
        config(['cms.mfa_required' => true]);
        $this->actingAs($this->user('office'));
        $this->getJson('/api/v1/invoices')->assertForbidden();
        $this->actingAs($this->user('marketing', ['custom_permissions' => ['finance.manage']]));
        $this->getJson('/api/v1/invoices')->assertForbidden();
        $this->withSession(['mfa_passed' => true])->getJson('/api/v1/invoices')->assertOk();
    }

    public function test_delegated_team_manager_cannot_grant_owner_or_permissions_it_does_not_hold(): void
    {
        $manager = $this->user('office', ['custom_permissions' => ['team.manage']]);
        $technician = $this->user('technician');
        $this->actingAs($manager)->withSession(['auth.password_confirmed_at' => time()]);
        $this->postJson('/api/v1/invitations', ['name' => 'New Owner', 'email' => 'new-owner@example.test', 'role' => 'owner'])->assertForbidden();
        $this->patchJson('/api/v1/team/'.$technician->id, ['role' => 'owner'])->assertForbidden();
        $this->patchJson('/api/v1/team/'.$technician->id, ['custom_permissions' => ['*']])->assertForbidden();
        $this->assertSame('technician', $this->records->get('users', $technician->id)['role']);
    }

    public function test_internal_attachment_cannot_be_moved_into_a_client_conversation(): void
    {
        $owner = $this->user('owner');
        $internal = $this->records->create('conversations', ['title' => 'Internal', 'type' => 'internal', 'participants' => [$owner->id]]);
        $external = $this->records->create('conversations', ['title' => 'Client', 'type' => 'client', 'participants' => [$owner->id]]);
        $file = $this->records->create('files', ['owner_id' => $owner->id, 'status' => 'ready', 'resource_type' => 'conversations', 'resource_id' => $internal['id']]);
        $this->actingAs($owner)->postJson('/api/v1/conversations/'.$external['id'].'/messages', ['body' => 'Shared attachment', 'file_ids' => [$file['id']], 'idempotency_key' => 'attachment-move'])->assertForbidden();
        $this->assertSame($internal['id'], $this->records->get('files', $file['id'])['resource_id']);
        $this->assertCount(0, $this->records->all('messages'));
    }

    public function test_unverified_client_cannot_read_existing_chat_membership(): void
    {
        $client = $this->user('client', ['email_verified_at' => null, 'customer_id' => 'customer']);
        $conversation = $this->records->create('conversations', ['title' => 'Private', 'type' => 'client', 'participants' => [$client->id]]);
        $this->actingAs($client)->getJson('/api/v1/conversations/'.$conversation['id'].'/messages')->assertForbidden();
        $this->getJson('/api/v1/conversations')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/team')->assertForbidden();
    }

    public function test_expired_mfa_challenge_cannot_complete(): void
    {
        $user = $this->user('owner');
        $this->withSession(['mfa_pending' => $user->id, 'mfa_pending_at' => time() - 601, 'mfa_pending_session_version' => 0])->postJson('/mfa', ['code' => '123456'])->assertUnprocessable();
        $this->assertGuest();
    }

    public function test_state_changes_require_csrf_and_only_exact_gateway_endpoints_are_exempt(): void
    {
        $this->actingAs($this->user('owner'));
        $this->app['env'] = 'production';
        $this->postJson('/api/v1/invoices', [])->assertStatus(419);
        $this->postJson('/api/v1/payments/example/refund', [])->assertStatus(419);
        Settings::put('payments', ['stripe_enabled' => true, 'stripe_secret_key' => 'sk_test_only', 'stripe_webhook_secret' => 'whsec_only']);
        $this->postJson('/api/v1/payments/webhooks/stripe', [])->assertBadRequest();
    }

    public function test_encrypted_backup_round_trip_preserves_private_files_and_records(): void
    {
        Storage::fake('local');
        $maintenance = \Mockery::mock(MaintenanceMode::class);
        $maintenance->shouldReceive('active')->andReturn(true);
        $this->app->instance(MaintenanceMode::class, $maintenance);
        $user = $this->user('owner');
        Storage::disk('local')->put('documents/private.txt', 'Private document content');
        $service = app(BackupService::class);
        $archive = $service->create('long-example-backup-password');
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($archive));
        $this->assertSame(\ZipArchive::EM_AES_256, $zip->statName('snapshot.json')['encryption_method']);
        $zip->close();
        DB::table('cms_records')->delete();
        DB::table('cms_locks')->delete();
        Storage::disk('local')->delete('documents/private.txt');
        $service->restore($archive, 'long-example-backup-password');
        $this->assertSame($user->email, $this->records->get('users', $user->id)['email']);
        $this->assertSame('Private document content', Storage::disk('local')->get('documents/private.txt'));
    }

    public function test_backups_reject_symlinks_in_private_storage(): void
    {
        Storage::fake('local');
        $maintenance = \Mockery::mock(MaintenanceMode::class);
        $maintenance->shouldReceive('active')->andReturn(true);
        $this->app->instance(MaintenanceMode::class, $maintenance);
        $outside = sys_get_temp_dir().'/cms-security-'.Str::uuid();
        mkdir($outside, 0700);
        $link = Storage::disk('local')->path('linked');
        symlink($outside, $link);
        try {
            app(BackupService::class)->create('long-example-backup-password');
            $this->fail('A symlink in private storage must be rejected.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Symlinks', $exception->getMessage());
        } finally {
            unlink($link);
            rmdir($outside);
        }
    }

    public function test_resetting_password_invalidates_other_outstanding_reset_links(): void
    {
        $user = $this->user('office');
        foreach (['first-reset-link', 'second-reset-link'] as $token) {
            $this->records->create('tokens', ['type' => 'reset', 'user_id' => $user->id, 'expires_at' => now()->addMinutes(30)->toISOString(), 'used_at' => null], hash('sha256', $token));
        }
        $this->postJson('/reset-password', ['token' => 'first-reset-link', 'password' => 'A replacement password', 'password_confirmation' => 'A replacement password'])->assertRedirect('/login');
        $this->postJson('/reset-password', ['token' => 'second-reset-link', 'password' => 'Another replacement password', 'password_confirmation' => 'Another replacement password'])->assertUnprocessable();
        $stored = $this->records->get('users', $user->id);
        $this->assertTrue(Hash::check('A replacement password', $stored['password']));
        $this->assertSame(1, $stored['session_version']);
    }

    public function test_unverified_client_cannot_download_a_conversation_attachment(): void
    {
        $client = $this->user('client', ['email_verified_at' => null, 'customer_id' => 'customer']);
        $conversation = $this->records->create('conversations', ['title' => 'Client', 'type' => 'client', 'participants' => [$client->id]]);
        $file = $this->records->create('files', ['owner_id' => 'staff', 'name' => 'document.txt', 'path' => 'uploads/private.txt', 'mime' => 'text/plain', 'status' => 'ready', 'visibility' => 'private', 'resource_type' => 'conversations', 'resource_id' => $conversation['id']]);
        $this->actingAs($client)->get('/files/'.$file['id'])->assertForbidden();
    }

    public function test_restore_invalidates_only_regular_files_matching_laravel_session_identifiers(): void
    {
        Storage::fake('local');
        $maintenance = \Mockery::mock(MaintenanceMode::class);
        $maintenance->shouldReceive('active')->andReturn(true);
        $this->app->instance(MaintenanceMode::class, $maintenance);
        $service = app(BackupService::class);
        $archive = $service->create('long-example-backup-password');
        $directory = sys_get_temp_dir().'/cms-sessions-'.Str::uuid();
        mkdir($directory, 0700);
        $sessionId = Str::random(40);
        file_put_contents($directory.'/'.$sessionId, 'Old authenticated session');
        file_put_contents($directory.'/README.txt', 'Keep this file');
        config(['session.driver' => 'file', 'session.files' => $directory]);
        try {
            $service->restore($archive, 'long-example-backup-password');
            $this->assertFileDoesNotExist($directory.'/'.$sessionId);
            $this->assertSame('Keep this file', file_get_contents($directory.'/README.txt'));
        } finally {
            File::deleteDirectory($directory);
        }
    }
}

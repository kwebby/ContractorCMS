<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace Tests\Feature;

use App\Contracts\RecordRepository;
use App\Identity\CmsUser;
use App\Support\Settings;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
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

    private function invite(CmsUser $owner, string $email, string $role = 'technician'): string
    {
        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()])->postJson('/api/v1/invitations', ['name' => 'Jordan Lee', 'email' => $email, 'role' => $role])->assertCreated();
        $email = collect($this->records->all('outbox'))->first(fn (array $event): bool => ($event['payload']['template_key'] ?? null) === 'invitation');
        preg_match('~/invitations/([a-zA-Z0-9]+)~', $email['payload']['body'], $match);
        auth()->logout();

        return $match[1];
    }

    public function test_staff_invitation_is_queued_with_the_invitee_name(): void
    {
        $owner = $this->user('owner');
        $this->invite($owner, 'jordan@example.test');
        $email = collect($this->records->all('outbox'))->first(fn (array $event): bool => ($event['payload']['template_key'] ?? null) === 'invitation');
        $this->assertSame('jordan@example.test', $email['payload']['to']);
        $this->assertStringContainsString('Jordan Lee', $email['payload']['body']);
        $this->assertSame('team.invited', collect($this->records->all('audit'))->last()['action']);
    }

    public function test_invitation_is_rejected_when_the_inviter_lost_access(): void
    {
        $owner = $this->user('owner');
        $token = $this->invite($owner, 'jordan@example.test');
        $this->records->update('users', $owner->id, ['active' => false]);
        $this->postJson('/invitations/accept', ['token' => $token, 'name' => 'Jordan Lee', 'password' => 'long-password-123', 'password_confirmation' => 'long-password-123'])->assertUnprocessable();
        $this->assertSame([], $this->records->all('users', ['email' => 'jordan@example.test']));
    }

    public function test_staff_invitation_replaces_an_unverified_client_signup_for_the_same_email(): void
    {
        $owner = $this->user('owner');
        $squatter = $this->user('client', ['email' => 'jordan@example.test', 'email_verified_at' => null, 'customer_id' => 'signup-customer', 'session_version' => 2]);
        $token = $this->invite($owner, 'jordan@example.test');
        $this->post('/invitations/accept', ['token' => $token, 'name' => 'Jordan Lee', 'password' => 'long-password-123', 'password_confirmation' => 'long-password-123'])->assertRedirect('/app');
        $account = $this->records->get('users', $squatter->id);
        $this->assertSame('technician', $account['role']);
        $this->assertNull($account['customer_id']);
        $this->assertNotEmpty($account['email_verified_at']);
        $this->assertSame(3, $account['session_version']);
        $this->assertTrue(Hash::check('long-password-123', $account['password']));
        $this->assertCount(1, $this->records->all('users', ['email' => 'jordan@example.test']));
    }

    public function test_staff_invitation_does_not_replace_a_verified_client_account(): void
    {
        $owner = $this->user('owner');
        $client = $this->user('client', ['email' => 'jordan@example.test', 'customer_id' => 'customer']);
        $token = $this->invite($owner, 'jordan@example.test');
        $this->postJson('/invitations/accept', ['token' => $token, 'name' => 'Jordan Lee', 'password' => 'long-password-123', 'password_confirmation' => 'long-password-123'])->assertConflict();
        $this->assertSame('client', $this->records->get('users', $client->id)['role']);
    }

    public function test_mfa_challenge_locks_after_repeated_invalid_codes(): void
    {
        $totp = new Google2FA;
        $secret = $totp->generateSecretKey();
        $this->user('owner', ['email' => 'owner@example.test', 'password' => Hash::make('long-owner-password'), 'mfa_enabled' => true, 'mfa_secret' => Crypt::encryptString($secret)]);
        $login = ['email' => 'owner@example.test', 'password' => 'long-owner-password'];
        $valid = $totp->getCurrentOtp($secret);
        $invalid = str_pad((string) (((int) $valid + 500000) % 1000000), 6, '0', STR_PAD_LEFT);
        $this->post('/login', $login)->assertRedirect('/mfa');
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/mfa', ['code' => $invalid])->assertUnprocessable();
        }
        $this->assertNull(session('mfa_pending'));
        $this->post('/login', $login)->assertRedirect('/mfa');
        $this->postJson('/mfa', ['code' => $valid])->assertUnprocessable()->assertJsonPath('errors.code.0', 'Too many invalid codes. Wait 15 minutes, then sign in again.');
        $this->assertGuest();
        $this->travel(16)->minutes();
        $this->post('/login', $login)->assertRedirect('/mfa');
        $this->post('/mfa', ['code' => $totp->getCurrentOtp($secret)])->assertRedirect('/app');
        $this->assertAuthenticated();
    }

    public function test_portal_invitation_for_an_mfa_account_requires_the_authentication_code(): void
    {
        $totp = new Google2FA;
        $secret = $totp->generateSecretKey();
        $client = $this->user('client', ['email' => 'client@example.test', 'password' => Hash::make('correct-password-123'), 'customer_id' => 'old', 'mfa_enabled' => true, 'mfa_secret' => Crypt::encryptString($secret)]);
        $this->records->create('tokens', ['type' => 'client_invite', 'customer_id' => 'new', 'email' => 'client@example.test', 'name' => 'Avery', 'expires_at' => now()->addHour()->toISOString(), 'used_at' => null, 'invited_by' => 'office'], hash('sha256', 'invitationtoken'));
        $this->post('/portal-invitations/accept', ['token' => 'invitationtoken', 'name' => 'Avery', 'password' => 'correct-password-123', 'password_confirmation' => 'correct-password-123'])->assertRedirect('/mfa');
        $this->assertGuest();
        $this->assertSame('new', $this->records->get('users', $client->id)['customer_id']);
        $this->post('/mfa', ['code' => $totp->getCurrentOtp($secret)])->assertRedirect('/app');
        $this->assertAuthenticatedAs(auth()->user());
        $this->assertSame($client->id, auth()->id());
    }

    public function test_reports_only_include_invoices_for_finance_roles(): void
    {
        $this->records->create('invoices', ['customer_id' => 'c1', 'status' => 'issued', 'total_minor' => 1000, 'currency' => 'USD']);
        $this->records->create('jobs', ['title' => 'Repair', 'customer_id' => 'c1', 'actual_cost_minor' => 400]);
        $this->actingAs($this->user('dispatcher'))->getJson('/api/v1/reports')->assertOk()->assertJsonCount(0, 'data.invoices')->assertJsonCount(1, 'data.jobs');
        $this->actingAs($this->user('finance'))->getJson('/api/v1/reports')->assertOk()->assertJsonCount(1, 'data.invoices');
    }

    public function test_dashboard_activity_hides_finance_payroll_and_access_events_without_permission(): void
    {
        foreach (['page.published', 'payment.received', 'payslip.published', 'team.access_changed'] as $action) {
            $this->records->create('audit', ['action' => $action, 'actor_id' => 'owner', 'details' => []]);
        }
        $actions = array_column($this->actingAs($this->user('dispatcher'))->getJson('/api/v1/dashboard')->assertOk()->json('data.activity'), 'action');
        $this->assertSame(['page.published'], $actions);
        $actions = array_column($this->actingAs($this->user('owner'))->getJson('/api/v1/dashboard')->assertOk()->json('data.activity'), 'action');
        $this->assertCount(4, $actions);
    }

    public function test_dashboard_totals_are_not_mixed_across_currencies(): void
    {
        Settings::put('business', ['currency' => 'USD']);
        $this->records->create('invoices', ['customer_id' => 'c1', 'status' => 'issued', 'total_minor' => 1000, 'currency' => 'USD']);
        $this->records->create('invoices', ['customer_id' => 'c2', 'status' => 'issued', 'total_minor' => 700, 'currency' => 'CAD']);
        $metrics = $this->actingAs($this->user('owner'))->getJson('/api/v1/dashboard')->assertOk()->json('data.metrics');
        $this->assertSame(1000, $metrics['outstanding_minor']);
        $this->assertSame('USD', $metrics['currency']);
        $this->assertSame([['currency' => 'CAD', 'outstanding_minor' => 700, 'collected_minor' => 0]], $metrics['other_currencies']);
    }

    public function test_technician_update_responses_omit_cost_fields(): void
    {
        $technician = $this->user('technician');
        $job = $this->records->create('jobs', ['title' => 'Repair', 'customer_id' => 'c1', 'status' => 'scheduled', 'assigned_to' => [$technician->id], 'actual_cost_minor' => 400, 'estimated_total_minor' => 900]);
        $response = $this->actingAs($technician)->patchJson('/api/v1/jobs/'.$job['id'], ['status' => 'in_progress', 'expected_version' => $job['version']])->assertOk();
        $this->assertArrayNotHasKey('actual_cost_minor', $response->json('data'));
        $this->assertArrayNotHasKey('estimated_total_minor', $response->json('data'));
    }

    public function test_team_directory_shows_contact_details_only_to_team_managers(): void
    {
        $this->user('office', ['email' => 'office@example.test']);
        $member = $this->actingAs($this->user('technician'))->getJson('/api/v1/team')->assertOk()->json('data.0');
        $this->assertArrayNotHasKey('email', $member);
        $this->assertArrayNotHasKey('email_verified_at', $member);
        $emails = array_column($this->actingAs($this->user('owner'))->getJson('/api/v1/team')->assertOk()->json('data'), 'email');
        $this->assertContains('office@example.test', $emails);
    }

    public function test_client_accounts_can_be_deactivated_but_not_given_staff_access(): void
    {
        $owner = $this->user('owner');
        $client = $this->user('client', ['customer_id' => 'c1']);
        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()])->patchJson('/api/v1/team/'.$client->id, ['role' => 'office'])->assertUnprocessable();
        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()])->patchJson('/api/v1/team/'.$client->id, ['active' => false])->assertOk();
        $account = $this->records->get('users', $client->id);
        $this->assertFalse($account['active']);
        $this->assertSame('client', $account['role']);
    }

    public function test_unverified_clients_cannot_upload_files(): void
    {
        $client = $this->user('client', ['email_verified_at' => null, 'customer_id' => 'c1']);
        $this->actingAs($client)->postJson('/api/v1/files', ['file' => UploadedFile::fake()->createWithContent('notes.txt', 'hello')])->assertForbidden();
    }

    public function test_login_rate_limiter_handles_non_string_email_input(): void
    {
        $this->postJson('/login', ['email' => ['owner@example.test'], 'password' => 'secret'])->assertUnprocessable();
    }

    public function test_inquiry_acknowledgements_are_limited_per_recipient(): void
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.'.$attempt])->postJson('/api/v1/public/leads', ['name' => 'Visitor', 'email' => 'target@example.test', 'service' => 'Repair'])->assertCreated();
        }
        $this->assertCount(5, $this->records->all('leads'));
        $acknowledgements = array_filter($this->records->all('outbox'), fn (array $event): bool => ($event['payload']['template_key'] ?? null) === 'inquiry');
        $this->assertCount(3, $acknowledgements);
    }

    public function test_trusted_proxies_come_from_configuration_at_request_time(): void
    {
        Route::get('/testing/client-ip', fn (Request $request): string => (string) $request->ip());
        config(['trustedproxy.proxies' => ['10.0.0.1']]);
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])->get('/testing/client-ip', ['X-Forwarded-For' => '203.0.113.9'])->assertSee('203.0.113.9');
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])->get('/testing/client-ip', ['X-Forwarded-For' => '203.0.113.9'])->assertSee('10.0.0.2');
    }

    public function test_trusted_proxy_configuration_is_read_from_the_environment(): void
    {
        $original = getenv('TRUSTED_PROXIES');
        putenv('TRUSTED_PROXIES=10.0.0.1, 10.0.0.0/8,');
        $_ENV['TRUSTED_PROXIES'] = $_SERVER['TRUSTED_PROXIES'] = '10.0.0.1, 10.0.0.0/8,';
        try {
            $this->assertSame(['proxies' => ['10.0.0.1', '10.0.0.0/8']], require config_path('trustedproxy.php'));
        } finally {
            putenv($original === false ? 'TRUSTED_PROXIES' : 'TRUSTED_PROXIES='.$original);
            unset($_ENV['TRUSTED_PROXIES'], $_SERVER['TRUSTED_PROXIES']);
        }
        $this->assertSame(['proxies' => null], require config_path('trustedproxy.php'));
    }

    public function test_trusted_hosts_follow_the_application_url_in_production(): void
    {
        $this->app->make(HttpKernel::class);
        config(['app.url' => 'https://cms.example.com']);
        $this->app['env'] = 'production';
        $this->assertSame(['^cms\.example\.com$'], $this->app->make(TrustHosts::class)->hosts());
        $this->app['env'] = 'staging';
        $this->assertSame([], $this->app->make(TrustHosts::class)->hosts());
    }

    public function test_security_headers_keep_a_stricter_referrer_policy(): void
    {
        $this->records->create('tokens', ['type' => 'client_invite', 'customer_id' => 'c1', 'email' => 'client@example.test', 'name' => 'Avery', 'expires_at' => now()->addHour()->toISOString(), 'used_at' => null, 'invited_by' => 'office'], hash('sha256', 'invitationtoken'));
        $this->get('/portal-invitations/invitationtoken')->assertOk()->assertHeader('Referrer-Policy', 'no-referrer');
        $this->get('/login')->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')->assertHeaderMissing('Strict-Transport-Security');
    }
}

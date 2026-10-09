<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace Tests\Feature;

use App\Automation\LeadToolService;
use App\Communications\NotificationDigest;
use App\Contracts\RecordRepository;
use App\Identity\CmsUser;
use App\Infrastructure\BackupService;
use App\Infrastructure\OutboxProcessor;
use App\Infrastructure\SqlRecordRepository;
use App\Support\Outbox;
use App\Support\Settings;
use Carbon\Exceptions\InvalidTimeZoneException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class InfrastructureHardeningTest extends TestCase
{
    use RefreshDatabase;

    private RecordRepository $records;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cms.data_provider' => 'postgres', 'cms.mfa_required' => false, 'mail.default' => 'array', 'app.url' => 'http://localhost']);
        $this->records = app(RecordRepository::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_outbox_only_leases_actionable_events_so_history_cannot_starve_new_mail(): void
    {
        foreach (range(1, 30) as $index) {
            $this->records->create('outbox', ['type' => 'email', 'payload' => [], 'status' => 'sent', 'attempts' => 1, 'available_at' => now()->toISOString()]);
        }
        foreach (range(1, 5) as $index) {
            $this->records->create('outbox', ['type' => 'email', 'payload' => [], 'status' => 'failed', 'attempts' => 5, 'available_at' => now()->toISOString()]);
        }
        $later = $this->records->create('outbox', ['type' => 'email', 'payload' => ['to' => 'later@example.test', 'subject' => 'Later', 'body' => 'Later'], 'status' => 'pending', 'attempts' => 1, 'available_at' => now()->addHour()->toISOString()]);
        $first = Outbox::push('email', ['to' => 'first@example.test', 'subject' => 'First', 'body' => 'First']);
        $second = Outbox::push('email', ['to' => 'second@example.test', 'subject' => 'Second', 'body' => 'Second']);
        $spy = $this->recordingRepository();

        $this->assertSame(['processed' => 1, 'failed' => 0], app(OutboxProcessor::class)->run(1));
        $this->assertSame(['processed' => 1, 'failed' => 0], app(OutboxProcessor::class)->run(1));

        $leased = array_values(array_unique(array_filter($spy->locks, fn (string $key): bool => str_starts_with($key, 'outbox-lease:'))));
        $this->assertEqualsCanonicalizing(['outbox-lease:'.$first['id'], 'outbox-lease:'.$second['id']], $leased);
        $this->assertSame('sent', $this->records->get('outbox', $first['id'])['status']);
        $this->assertSame('sent', $this->records->get('outbox', $second['id'])['status']);
        $this->assertSame('pending', $this->records->get('outbox', $later['id'])['status']);
        $this->assertSame(1, $this->records->get('outbox', $later['id'])['attempts']);
    }

    public function test_abandoned_outbox_lease_fails_after_the_attempt_cap_instead_of_retrying_forever(): void
    {
        $exhausted = Outbox::push('email', ['to' => 'exhausted@example.test', 'subject' => 'Exhausted', 'body' => 'Exhausted']);
        $this->records->update('outbox', $exhausted['id'], ['status' => 'processing', 'lease_until' => now()->subMinute()->toISOString(), 'attempts' => 5]);
        $retryable = Outbox::push('email', ['to' => 'retry@example.test', 'subject' => 'Retry', 'body' => 'Retry']);
        $this->records->update('outbox', $retryable['id'], ['status' => 'processing', 'lease_until' => now()->subMinute()->toISOString(), 'attempts' => 2]);

        $this->assertSame(['processed' => 1, 'failed' => 1], app(OutboxProcessor::class)->run());

        $failed = $this->records->get('outbox', $exhausted['id']);
        $this->assertSame('failed', $failed['status']);
        $this->assertSame(5, $failed['attempts']);
        $this->assertSame('LeaseExpired', $failed['last_error']);
        $this->assertNull($failed['lease_until']);
        $this->assertSame('sent', $this->records->get('outbox', $retryable['id'])['status']);
        $this->assertSame(3, $this->records->get('outbox', $retryable['id'])['attempts']);
        $this->assertSame(['processed' => 0, 'failed' => 0], app(OutboxProcessor::class)->run());
    }

    public function test_health_reports_queue_state_from_status_filtered_queries(): void
    {
        $this->records->create('outbox', ['type' => 'email', 'payload' => [], 'status' => 'sent', 'attempts' => 1]);
        $pending = $this->records->create('outbox', ['type' => 'email', 'payload' => [], 'status' => 'pending', 'attempts' => 0]);
        $this->records->create('outbox', ['type' => 'email', 'payload' => [], 'status' => 'processing', 'attempts' => 1]);
        $failed = $this->records->create('outbox', ['type' => 'email', 'payload' => ['body' => 'secret'], 'status' => 'failed', 'attempts' => 5, 'last_error' => 'TransportException']);
        $spy = $this->recordingRepository();
        $owner = new CmsUser($this->records->create('users', ['name' => 'Owner', 'email' => 'owner@example.test', 'role' => 'owner', 'password' => Hash::make('Strong password here'), 'active' => true, 'email_verified_at' => now()->toISOString()]));

        $data = $this->actingAs($owner)->getJson('/api/v1/health')->assertOk()->json('data');

        $this->assertSame(1, $data['pending']);
        $this->assertSame($pending['created_at'], $data['queue_oldest_at']);
        $this->assertEquals([['id' => $failed['id'], 'type' => 'email', 'attempts' => 5, 'last_error' => 'TransportException', 'updated_at' => $failed['updated_at']]], $data['failed']);
        $this->assertNotContains(['outbox', []], $spy->scans);
    }

    public function test_digest_failure_does_not_stop_outbox_delivery_or_heartbeat(): void
    {
        Exceptions::fake();
        Settings::put('notifications', ['email_enabled' => true, 'digest' => 'daily']);
        Settings::put('business', ['name' => 'River Plumbing', 'timezone' => 'Invalid/Zone']);
        $email = Outbox::push('email', ['to' => 'pat@example.test', 'subject' => 'Hello', 'body' => 'Hello']);

        $this->artisan('cms:outbox')->assertSuccessful();

        Exceptions::assertReported(InvalidTimeZoneException::class);
        $this->assertSame('sent', $this->records->get('outbox', $email['id'])['status']);
        $this->assertNotEmpty(Settings::get('scheduler')['last_run_at']);
    }

    public function test_daily_digest_rescans_users_at_most_once_per_interval(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-09 15:00:00', 'UTC'));
        Settings::put('business', ['name' => 'River Plumbing', 'timezone' => 'UTC']);
        Settings::put('notifications', ['email_enabled' => true, 'digest' => 'daily']);
        $this->records->create('users', ['name' => 'Pat', 'email' => 'pat@example.test', 'email_verified_at' => now()->toIso8601String(), 'active' => true], 'pat');
        $digest = app(NotificationDigest::class);
        $this->assertSame(0, $digest->enqueueDaily());
        $this->records->create('notifications', ['user_id' => 'pat', 'title' => 'Update', 'read_at' => null]);

        Carbon::setTestNow(Carbon::parse('2026-10-09 15:30:00', 'UTC'));
        $spy = $this->recordingRepository();
        $this->assertSame(0, app(NotificationDigest::class)->enqueueDaily());
        $this->assertNotContains('notifications', array_column($spy->scans, 0));

        Carbon::setTestNow(Carbon::parse('2026-10-09 16:01:00', 'UTC'));
        $this->assertSame(1, app(NotificationDigest::class)->enqueueDaily());
        $this->assertCount(1, $this->records->all('outbox', ['type' => 'email']));
    }

    public function test_mysql_connections_read_committed_rows_after_waiting_for_a_lock(): void
    {
        foreach (['mysql', 'mariadb'] as $connection) {
            $this->assertSame('READ COMMITTED', config('database.connections.'.$connection.'.isolation_level'));
        }
    }

    public function test_mysql_key_columns_use_case_sensitive_collation_and_other_drivers_are_untouched(): void
    {
        $migration = require database_path('migrations/2026_10_09_154541_use_binary_collation_for_cms_record_keys.php');
        $this->assertSame([], DB::connection()->pretend(fn () => $migration->up()));
        $default = config('database.default');
        try {
            foreach (['mysql', 'mariadb'] as $connection) {
                config(['database.default' => $connection]);
                $up = array_column(DB::connection($connection)->pretend(fn () => $migration->up()), 'query');
                $down = array_column(DB::connection($connection)->pretend(fn () => $migration->down()), 'query');
                foreach (['`cms_records` modify `collection` varchar(64)', '`cms_records` modify `id` varchar(128)', '`cms_locks` modify `key` varchar(64)'] as $column) {
                    $this->assertNotEmpty(array_filter($up, fn (string $sql): bool => str_contains($sql, $column) && str_contains($sql, "collate 'utf8mb4_bin'")), $connection.': '.$column);
                    $this->assertNotEmpty(array_filter($down, fn (string $sql): bool => str_contains($sql, $column) && str_contains($sql, "collate 'utf8mb4_unicode_ci'")), $connection.': '.$column);
                }
            }
        } finally {
            config(['database.default' => $default]);
        }
    }

    public function test_backup_limits_match_restore_and_fail_before_writing(): void
    {
        $service = app(BackupService::class);
        $service->assertRestorable(['snapshot.json' => 1024, 'files/documents/invoices/a.pdf' => BackupService::MAX_ENTRY_BYTES]);
        $cases = [
            ['snapshot.json' => BackupService::MAX_ENTRY_BYTES + 1],
            ['snapshot.json' => 1024] + array_fill_keys(array_map(fn (int $index): string => 'files/media/'.$index.'.jpg', range(1, BackupService::MAX_ENTRIES)), 1),
            ['snapshot.json' => BackupService::MAX_ENTRY_BYTES, 'files/a.pdf' => BackupService::MAX_ENTRY_BYTES] + array_fill_keys(array_map(fn (int $index): string => 'files/media/'.$index.'.jpg', range(1, 15)), BackupService::MAX_ENTRY_BYTES),
        ];
        foreach ($cases as $entries) {
            try {
                $service->assertRestorable($entries);
                $this->fail('Oversized backup was accepted.');
            } catch (\RuntimeException $error) {
                $this->assertStringContainsString('database-native dump', $error->getMessage());
            }
        }
        $this->expectExceptionMessage('Rename "files/media/my photo.jpg"');
        $service->assertRestorable(['snapshot.json' => 1024, 'files/media/my photo.jpg' => 10]);
    }

    public function test_query_exception_messages_do_not_contain_record_values(): void
    {
        foreach (array_keys(config('database.connections')) as $connection) {
            $this->assertTrue(config('database.connections.'.$connection.'.mask_bindings_in_exception_messages'), $connection);
        }
        $this->records->create('users', ['email' => 'pat@example.test', 'password' => 'hash-sentinel-value'], 'pat');
        try {
            DB::table('cms_records')->insert(['collection' => 'users', 'id' => 'pat', 'data' => json_encode(['password' => 'hash-sentinel-value']), 'version' => 1, 'created_at' => now()->toISOString(), 'updated_at' => now()->toISOString()]);
            $this->fail('Duplicate record was inserted.');
        } catch (QueryException $error) {
            $this->assertStringNotContainsString('hash-sentinel-value', $error->getMessage());
        }
    }

    public function test_delivered_email_drops_secret_links_but_retries_keep_them(): void
    {
        $token = Str::random(64);
        $url = 'http://localhost/reset-password/'.$token;
        $event = Outbox::push('email', ['to' => 'pat@example.test', 'template_key' => 'password_reset', 'template_data' => ['recipient_name' => 'Pat', 'action_url' => $url]]);
        config(['mail.default' => 'unavailable']);

        $this->assertSame(['processed' => 0, 'failed' => 1], app(OutboxProcessor::class)->run());
        $retry = $this->records->get('outbox', $event['id']);
        $this->assertSame('pending', $retry['status']);
        $this->assertStringContainsString($url, $retry['payload']['body']);

        config(['mail.default' => 'array']);
        $this->records->update('outbox', $event['id'], ['available_at' => now()->subSecond()->toISOString()]);
        $this->assertSame(['processed' => 1, 'failed' => 0], app(OutboxProcessor::class)->run());
        $sent = $this->records->get('outbox', $event['id']);
        $this->assertSame('sent', $sent['status']);
        $this->assertStringNotContainsString($token, json_encode($sent));
        $this->assertSame('pat@example.test', $sent['payload']['to']);
        $this->assertSame('password_reset', $sent['payload']['template_key']);
        $this->assertSame('Reset your password', $sent['payload']['subject']);
        $this->assertArrayNotHasKey('body', $sent['payload']);
        $messages = app('mail.manager')->mailer('array')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $this->assertStringContainsString($url, $messages[0]->getOriginalMessage()->getTextBody());
    }

    public function test_price_guard_accepts_restated_amounts_with_punctuation_and_rejects_invented_prices(): void
    {
        $this->enableTools();
        $details = 'Quote A: drain repair for $4,500. Quote B: USD 3,900.00 with permit fees unknown.';
        $service = app(LeadToolService::class);
        $summary = '';
        Http::fake(['api.openai.com/*' => function () use (&$summary) {
            return Http::response($this->providerResponse($summary));
        }]);
        foreach (['Quote A lists $4,500.', 'Quote A lists $4,500, while Quote B lists USD 3,900.', 'Quote B lists USD 3900 and Quote A lists $ 4,500!'] as $summary) {
            $this->assertSame('completed', $service->generate('quote-comparison', $details, (string) Str::uuid(), 'visitor')['status']);
        }
        foreach (['Quote A lists $4,500 but expect $5,200.', 'Quote A lists $4.'] as $summary) {
            try {
                $service->generate('quote-comparison', $details, (string) Str::uuid(), 'visitor');
                $this->fail('Invented price was accepted: '.$summary);
            } catch (HttpException $error) {
                $this->assertSame(502, $error->getStatusCode());
            }
        }
    }

    public function test_abandoned_generation_is_reclaimed_after_its_lease_and_reserves_budget_again(): void
    {
        $this->enableTools();
        Http::fake(['api.openai.com/*' => Http::response($this->providerResponse('Prepare a project discussion.'))]);
        $details = 'I want to improve the kitchen storage and lighting.';
        $requestId = (string) Str::uuid();
        $id = hash('sha256', 'visitor|project-scope|'.$requestId);
        $day = now()->utc()->format('Y-m-d');
        $this->records->create('ai_usage', ['requests' => 1, 'tokens' => 5000], $day);
        $this->records->create('tool_runs', ['tool' => 'project-scope', 'status' => 'processing', 'visitor_hash' => 'visitor', 'input_hash' => hash('sha256', $details), 'input' => $details, 'reservation' => 5000, 'budget_day' => $day, 'expires_at' => now()->addDays(7)->toIso8601String(), 'started_at' => now()->subSeconds(30)->toIso8601String()], $id);
        $service = app(LeadToolService::class);
        try {
            $service->generate('project-scope', $details, $requestId, 'visitor');
            $this->fail('An active generation was started twice.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        Http::assertNothingSent();

        $this->records->update('tool_runs', $id, ['started_at' => now()->subMinutes(10)->toIso8601String()]);
        $this->assertSame('completed', $service->generate('project-scope', $details, $requestId, 'visitor')['status']);

        Http::assertSentCount(1);
        $usage = $this->records->get('ai_usage', $day);
        $this->assertSame(2, $usage['requests']);
        $this->assertSame(5000 + 410, $usage['tokens']);
        $this->assertSame(1, $this->records->get('tool_runs', $id)['reclaimed_attempts']);
    }

    public function test_prune_deletes_expired_tool_input_requests_and_tokens(): void
    {
        $this->records->create('tool_runs', ['status' => 'completed', 'input' => 'Old private input', 'expires_at' => now()->subMinute()->toIso8601String()], 'expired-run');
        $this->records->create('tool_report_requests', ['run_id' => 'expired-run', 'email' => 'old@example.test', 'status' => 'verified', 'token_hash' => 'old-token', 'expires_at' => now()->addHour()->toIso8601String()], 'old-request');
        $this->records->create('tool_report_tokens', ['request_id' => 'old-request'], 'old-token');
        $this->records->create('tool_runs', ['status' => 'completed', 'input' => 'Current input', 'expires_at' => now()->addDays(3)->toIso8601String()], 'run');
        $this->records->create('tool_report_requests', ['run_id' => 'run', 'email' => 'lapsed@example.test', 'status' => 'pending', 'token_hash' => 'lapsed-token', 'expires_at' => now()->subMinute()->toIso8601String()], 'lapsed-request');
        $this->records->create('tool_report_tokens', ['request_id' => 'lapsed-request'], 'lapsed-token');
        $this->records->create('tool_report_requests', ['run_id' => 'run', 'email' => 'verified@example.test', 'status' => 'verified', 'token_hash' => 'verified-token', 'expires_at' => now()->subMinute()->toIso8601String()], 'verified-request');
        $this->records->create('tool_report_tokens', ['request_id' => 'verified-request'], 'verified-token');
        $this->records->create('tool_report_requests', ['run_id' => 'run', 'email' => 'active@example.test', 'status' => 'pending', 'token_hash' => 'active-token', 'expires_at' => now()->addHour()->toIso8601String()], 'active-request');
        $this->records->create('tool_report_tokens', ['request_id' => 'active-request'], 'active-token');
        $this->records->create('tool_report_tokens', ['request_id' => 'missing-request'], 'orphan-token');

        $this->artisan('cms:prune')->assertSuccessful();

        $this->assertSame(['run'], array_column($this->records->all('tool_runs'), 'id'));
        $this->assertEqualsCanonicalizing(['verified-request', 'active-request'], array_column($this->records->all('tool_report_requests'), 'id'));
        $this->assertSame(['active-token'], array_column($this->records->all('tool_report_tokens'), 'id'));
        Artisan::call('schedule:list');
        $this->assertStringContainsString('cms:prune', Artisan::output());
    }

    public function test_report_can_be_requested_again_after_the_confirmation_expires(): void
    {
        $this->enableTools();
        Http::fake(['api.openai.com/*' => Http::response($this->providerResponse('Prepare a project discussion.'))]);
        $service = app(LeadToolService::class);
        $run = $service->generate('project-scope', 'I want to improve the kitchen storage and lighting.', (string) Str::uuid(), 'visitor');
        $contact = ['name' => 'Pat Lee', 'email' => 'pat@example.test', 'marketing_consent' => false, 'contact_requested' => true];
        $service->requestReport($run['id'], 'visitor', $contact);
        $request = $this->records->all('tool_report_requests')[0];
        $firstEmail = $this->records->all('outbox')[0];
        $firstToken = $this->confirmationToken($firstEmail);
        $this->records->update('tool_report_requests', $request['id'], ['expires_at' => now()->subMinute()->toIso8601String()]);

        $service->requestReport($run['id'], 'visitor', $contact);
        $service->requestReport($run['id'], 'visitor', $contact);

        $this->assertCount(2, $this->records->all('outbox'));
        $secondToken = $this->confirmationToken(array_values(array_filter($this->records->all('outbox'), fn (array $email): bool => $email['id'] !== $firstEmail['id']))[0]);
        $this->assertNotSame($firstToken, $secondToken);
        $this->assertNull($this->records->find('tool_report_tokens', hash('sha256', $firstToken)));
        $service->verifyReport($secondToken);
        $this->assertCount(1, $this->records->all('leads'));
        $this->assertSame('verified', $this->records->get('tool_report_requests', $request['id'])['status']);
    }

    public function test_settings_writes_are_serialized_by_a_group_lock(): void
    {
        $spy = $this->recordingRepository();
        Settings::put('business', ['name' => 'First']);
        Settings::put('business', ['name' => 'Second']);

        $this->assertSame(['settings:business', 'settings:business'], array_values(array_filter($spy->locks, fn (string $key): bool => str_starts_with($key, 'settings:'))));
        $this->assertSame('Second', Settings::get('business')['name']);
        $this->assertSame(2, $this->records->get('settings', 'business')['version']);
    }

    private function confirmationToken(array $email): string
    {
        preg_match('~/tools/reports/verify/([a-zA-Z0-9]{64})~', $email['payload']['body'], $matches);

        return $matches[1];
    }

    private function enableTools(): void
    {
        Http::preventStrayRequests();
        if (! Route::has('tools.page')) {
            Route::middleware('web')->group(base_path('routes/automation.php'));
        }
        Settings::put('ai', ['enabled' => true, 'api_key' => 'mock-key-only', 'model' => 'test-model', 'daily_request_limit' => 100, 'daily_token_limit' => 100000]);
    }

    private function providerResponse(string $summary): array
    {
        return ['id' => 'resp_test', 'status' => 'completed', 'usage' => ['total_tokens' => 410], 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode([
            'summary' => $summary, 'known_facts' => [], 'questions' => [], 'next_steps' => [], 'unknowns' => [],
        ])]]]]];
    }

    /**
     * Wrap the SQL repository so tests can see which collections were scanned and which locks were taken.
     */
    private function recordingRepository(): RecordRepository
    {
        $spy = new class(app(SqlRecordRepository::class)) implements RecordRepository
        {
            /** @var list<string> */
            public array $locks = [];

            /** @var list<array{0: string, 1: array}> */
            public array $scans = [];

            public function __construct(private readonly RecordRepository $inner) {}

            public function all(string $collection, array $where = []): array
            {
                $this->scans[] = [$collection, $where];

                return $this->inner->all($collection, $where);
            }

            public function page(string $collection, array $where = [], int $limit = 50, ?string $cursor = null): array
            {
                return $this->inner->page($collection, $where, $limit, $cursor);
            }

            public function find(string $collection, string $id): ?array
            {
                return $this->inner->find($collection, $id);
            }

            public function get(string $collection, string $id): array
            {
                return $this->inner->get($collection, $id);
            }

            public function create(string $collection, array $data, ?string $id = null): array
            {
                return $this->inner->create($collection, $data, $id);
            }

            public function update(string $collection, string $id, array $data, ?int $expectedVersion = null): array
            {
                return $this->inner->update($collection, $id, $data, $expectedVersion);
            }

            public function delete(string $collection, string $id): void
            {
                $this->inner->delete($collection, $id);
            }

            public function transaction(callable $callback): mixed
            {
                return $this->inner->transaction($callback);
            }

            public function lock(string $key): void
            {
                $this->locks[] = $key;
                $this->inner->lock($key);
            }

            public function sequence(string $key): int
            {
                return $this->inner->sequence($key);
            }
        };
        $this->app->instance(RecordRepository::class, $spy);

        return $spy;
    }
}

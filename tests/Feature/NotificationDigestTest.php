<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace Tests\Feature;

use App\Communications\EmailTemplates;
use App\Communications\NotificationDigest;
use App\Contracts\RecordRepository;
use App\Infrastructure\OutboxProcessor;
use App\Support\Outbox;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class NotificationDigestTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_daily_digest_is_idempotent_and_contains_only_a_generic_count(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-09 15:00:00', 'UTC'));
        Settings::put('business', ['name' => 'River Plumbing', 'timezone' => 'America/New_York']);
        Settings::put('notifications', ['email_enabled' => true, 'digest' => 'daily']);
        $records = app(RecordRepository::class);
        $records->create('users', ['name' => 'Pat', 'email' => 'pat@example.test', 'email_verified_at' => now()->toIso8601String(), 'active' => true], 'pat');
        $records->create('notifications', ['user_id' => 'pat', 'title' => 'CONFIDENTIAL PAYROLL 12345', 'read_at' => null], 'new');
        $records->create('notifications', ['user_id' => 'pat', 'title' => 'Already read', 'read_at' => now()->toIso8601String()], 'read');
        $digest = app(NotificationDigest::class);
        $this->assertSame(1, $digest->enqueueDaily());
        $this->assertSame(0, $digest->enqueueDaily());
        $mail = $records->all('outbox');
        $this->assertCount(1, $mail);
        $this->assertStringContainsString('1 new unread update', $mail[0]['payload']['body']);
        $this->assertStringNotContainsString('CONFIDENTIAL', $mail[0]['payload']['body']);
        $this->assertNotNull($records->get('notifications', 'new')['email_queued_at']);
        $this->assertNull($records->get('notifications', 'new')['read_at']);
        Carbon::setTestNow(Carbon::parse('2026-10-10 15:00:00', 'UTC'));
        $this->assertSame(0, $digest->enqueueDaily());
    }

    public function test_disabled_before_morning_and_unverified_accounts_receive_no_digest(): void
    {
        $records = app(RecordRepository::class);
        $records->create('users', ['email' => 'pat@example.test', 'email_verified_at' => null], 'pat');
        $records->create('notifications', ['user_id' => 'pat', 'read_at' => null]);
        $digest = app(NotificationDigest::class);
        $this->assertSame(0, $digest->enqueueDaily());
        Settings::put('notifications', ['email_enabled' => true, 'digest' => 'daily']);
        Carbon::setTestNow(Carbon::parse('2026-10-09 08:00:00', 'UTC'));
        $this->assertSame(0, $digest->enqueueDaily());
        Carbon::setTestNow(Carbon::parse('2026-10-09 15:00:00', 'UTC'));
        $this->assertSame(0, $digest->enqueueDaily());
        $this->assertCount(0, $records->all('outbox'));
    }

    public function test_instant_delivery_is_deduplicated_and_preserves_event_template_version(): void
    {
        config(['mail.default' => 'array']);
        Settings::put('notifications', ['email_enabled' => true, 'digest' => 'instant']);
        $records = app(RecordRepository::class);
        $records->create('users', ['name' => 'Pat', 'role' => 'client', 'email' => 'pat@example.test', 'customer_id' => 'customer', 'email_verified_at' => now()->toIso8601String(), 'active' => true], 'pat');
        $records->create('invoices', ['number' => 'INV-001', 'currency' => 'USD', 'total_minor' => 25000], 'invoice');
        $event = Outbox::push('invoice.issued', ['invoice_id' => 'invoice', 'customer_id' => 'customer'], 'invoice-event');
        app(EmailTemplates::class)->update('invoice', ['subject' => 'New subject', 'body' => 'Changed template.', 'expected_version' => 0]);
        app(OutboxProcessor::class)->run();
        $emails = $records->all('outbox', ['type' => 'email']);
        $this->assertCount(1, $emails);
        $this->assertSame(0, $emails[0]['payload']['template_version']);
        $this->assertStringContainsString('INV-001', $emails[0]['payload']['subject']);
        $this->assertStringContainsString('USD 250.00', $emails[0]['payload']['body']);
        $records->update('outbox', $event['id'], ['status' => 'pending']);
        app(OutboxProcessor::class)->run();
        $this->assertCount(1, $records->all('outbox', ['type' => 'email']));
        $this->assertCount(1, $records->all('notifications'));
    }

    public function test_daily_setting_suppresses_instant_email_without_losing_notification(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-09 15:00:00', 'UTC'));
        Settings::put('notifications', ['email_enabled' => true, 'digest' => 'daily']);
        $records = app(RecordRepository::class);
        $records->create('users', ['name' => 'Pat', 'email' => 'pat@example.test', 'email_verified_at' => now()->toIso8601String(), 'active' => true], 'pat');
        Outbox::notify('pat', 'A private update', 'operations', '/app/jobs');
        $this->assertCount(1, $records->all('notifications'));
        $this->assertCount(0, $records->all('outbox'));
        $this->assertSame(1, app(NotificationDigest::class)->enqueueDaily());
        $this->assertCount(1, $records->all('outbox'));
    }
}

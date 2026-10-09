<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace Tests\Feature;

use App\Contracts\RecordRepository;
use App\Infrastructure\OutboxProcessor;
use App\Support\Outbox;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cms.data_provider' => 'postgres', 'cms.mfa_required' => false, 'mail.default' => 'array']);
    }

    public function test_outbox_retry_lease_and_event_idempotency(): void
    {
        $records = app(RecordRepository::class);
        $one = Outbox::push('email', ['to' => 'recipient@example.test', 'subject' => 'Test', 'body' => 'Hello'], 'mail-one');
        $two = Outbox::push('email', ['to' => 'recipient@example.test', 'subject' => 'Test', 'body' => 'Hello'], 'mail-one');
        $this->assertSame($one['id'], $two['id']);
        $this->assertSame(['processed' => 1, 'failed' => 0], app(OutboxProcessor::class)->run());
        $this->assertSame('sent', $records->get('outbox', $one['id'])['status']);
        $this->assertSame(['processed' => 0, 'failed' => 0], app(OutboxProcessor::class)->run());
        $this->assertNotEmpty(Settings::get('scheduler')['last_run_at']);
        $bad = Outbox::push('invalid.event', []);
        $this->assertSame(1, app(OutboxProcessor::class)->run()['failed']);
        $failed = $records->get('outbox', $bad['id']);
        $this->assertSame('pending', $failed['status']);
        $this->assertSame('UnexpectedValueException', $failed['last_error']);
        $records->update('outbox', $bad['id'], ['status' => 'processing', 'lease_until' => now()->subMinute()->toISOString(), 'attempts' => 4]);
        app(OutboxProcessor::class)->run();
        $this->assertSame('failed', $records->get('outbox', $bad['id'])['status']);
    }

    public function test_settings_credentials_are_encrypted_and_masked(): void
    {
        Settings::put('payments', ['stripe_secret_key' => 'sensitive-key', 'paypal_client_secret' => 'other-secret', 'paypal_mode' => 'sandbox']);
        $record = app(RecordRepository::class)->get('settings', 'payments');
        $this->assertArrayHasKey('encrypted', $record);
        $this->assertStringNotContainsString('sensitive-key', json_encode($record));
        $this->assertSame('sensitive-key', Settings::get('payments')['stripe_secret_key']);
        $this->assertSame('••••••••', Settings::masked('payments')['stripe_secret_key']);
    }

    public function test_finance_event_delivery_does_not_notify_unverified_or_unrelated_clients(): void
    {
        $records = app(RecordRepository::class);
        foreach ([['id' => 'a', 'customer_id' => 'c1', 'email_verified_at' => now()->toISOString()], ['id' => 'b', 'customer_id' => 'c2', 'email_verified_at' => now()->toISOString()], ['id' => 'c', 'customer_id' => 'c1', 'email_verified_at' => null]] as $user) {
            $records->create('users', $user + ['name' => 'Client', 'email' => $user['id'].'@example.test', 'role' => 'client', 'active' => true], $user['id']);
        }
        $event = Outbox::push('invoice.issued', ['invoice_id' => 'invoice', 'customer_id' => 'c1'], 'invoice-event');
        app(OutboxProcessor::class)->run();
        $this->assertCount(1, $records->all('notifications'));
        $this->assertSame('a', $records->all('notifications')[0]['user_id']);
        $records->update('outbox', $event['id'], ['status' => 'pending']);
        app(OutboxProcessor::class)->run();
        $this->assertCount(1, $records->all('notifications'));
    }
}

<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace App\Infrastructure;

use App\Communications\EmailTemplates;
use App\Communications\NotificationDigest;
use App\Contracts\RecordRepository;
use App\Finance\Money;
use App\Support\Settings;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class OutboxProcessor
{
    private const MAX_ATTEMPTS = 5;

    private const LEASE_MINUTES = 5;

    public function __construct(private RecordRepository $records) {}

    public function run(int $limit = 50, int $seconds = 45): array
    {
        if (app()->isDownForMaintenance()) {
            return ['processed' => 0, 'failed' => 0];
        }
        $started = microtime(true);
        $result = ['processed' => 0, 'failed' => 0];
        $this->heartbeat();
        foreach ($this->candidates() as $candidate) {
            if (array_sum($result) >= $limit || microtime(true) - $started > $seconds) {
                break;
            }
            $event = $this->claim($candidate['id']);
            if ($event === null) {
                continue;
            }
            if ($event['status'] === 'failed') {
                $result['failed']++;

                continue;
            }
            try {
                $this->dispatch($event);
                $this->finish($event, ['status' => 'sent', 'completed_at' => now()->toISOString(), 'last_error' => null] + ($event['type'] === 'email' ? ['payload' => $this->redact($event['payload'])] : []));
                $result['processed']++;
            } catch (\Throwable $error) {
                // Provider exceptions may contain credentials; only retain the exception class.
                $this->finish($event, ['status' => $event['attempts'] >= self::MAX_ATTEMPTS ? 'failed' : 'pending', 'available_at' => now()->addSeconds(min(3600, 30 * (2 ** $event['attempts'])))->toISOString(), 'last_error' => class_basename($error)]);
                $result['failed']++;
            }
        }
        $this->heartbeat();

        return $result;
    }

    /**
     * Due pending events and processing events whose lease expired, oldest first.
     * Sent and failed history is never scanned, so it cannot starve new work.
     *
     * @return list<array<string, mixed>>
     */
    private function candidates(): array
    {
        $now = now()->toISOString();
        $due = array_filter($this->records->all('outbox', ['status' => 'pending']), fn (array $row): bool => ($row['available_at'] ?? '') <= $now);
        $abandoned = array_filter($this->records->all('outbox', ['status' => 'processing']), fn (array $row): bool => ($row['lease_until'] ?? '') < $now);
        $candidates = [...array_values($due), ...array_values($abandoned)];
        usort($candidates, fn (array $left, array $right): int => [$left['created_at'], $left['id']] <=> [$right['created_at'], $right['id']]);

        return $candidates;
    }

    /**
     * Lease an eligible event, or fail an abandoned event that already used every attempt.
     *
     * @return array<string, mixed>|null
     */
    private function claim(string $id): ?array
    {
        return $this->records->transaction(function () use ($id): ?array {
            $this->records->lock('outbox-lease:'.$id);
            $row = $this->records->find('outbox', $id);
            if ($row === null) {
                return null;
            }
            $now = now()->toISOString();
            $due = $row['status'] === 'pending' && ($row['available_at'] ?? '') <= $now;
            $abandoned = $row['status'] === 'processing' && ($row['lease_until'] ?? '') < $now;
            if (! $due && ! $abandoned) {
                return null;
            }
            if ($abandoned && ($row['attempts'] ?? 0) >= self::MAX_ATTEMPTS) {
                return $this->records->update('outbox', $id, ['status' => 'failed', 'lease_until' => null, 'last_error' => 'LeaseExpired']);
            }

            return $this->records->update('outbox', $id, ['status' => 'processing', 'lease_token' => (string) Str::uuid(), 'lease_until' => now()->addMinutes(self::LEASE_MINUTES)->toISOString(), 'attempts' => ($row['attempts'] ?? 0) + 1]);
        });
    }

    /**
     * Drop delivered message content, which can carry sign-in, invitation or verification links,
     * while keeping the addressing and template metadata used for audit.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function redact(array $payload): array
    {
        return Arr::except($payload, ['body', 'text', 'action_url', 'template_data', '_template_snapshot']) + ['content_redacted_at' => now()->toISOString()];
    }

    private function finish(array $event, array $data): void
    {
        $this->records->transaction(function () use ($event, $data) {
            $this->records->lock('outbox-lease:'.$event['id']);
            if (($this->records->get('outbox', $event['id'])['lease_token'] ?? '') === $event['lease_token']) {
                $this->records->update('outbox', $event['id'], $data + ['lease_until' => null]);
            }
        });
    }

    private function dispatch(array $event): void
    {
        $payload = $event['payload'];
        if ($event['type'] === 'email') {
            $this->send($payload, $event['id']);

            return;
        }
        $titles = ['estimate.sent' => 'Your estimate is ready', 'estimate.accepted' => 'Estimate accepted', 'invoice.issued' => 'Your invoice is ready', 'payment.received' => 'Payment received', 'payslip.published' => 'Your payslip is ready'];
        if (! isset($titles[$event['type']])) {
            throw new \UnexpectedValueException('Unknown outbox event.');
        }
        $payroll = $event['type'] === 'payslip.published';
        foreach ($this->records->all('users') as $user) {
            $recipient = $payroll ? $user['id'] === ($payload['employee_user_id'] ?? '') : (($user['role'] === 'client' && ! empty($user['email_verified_at']) && ! empty($user['customer_id']) && $user['customer_id'] === ($payload['customer_id'] ?? null)) || ($event['type'] === 'estimate.accepted' && in_array($user['role'], ['owner', 'office', 'dispatcher'])));
            if (! $recipient || ! ($user['active'] ?? true)) {
                continue;
            }
            $url = '/app/'.($payroll ? 'payroll' : (str_starts_with($event['type'], 'estimate') ? 'estimates' : 'invoices'));
            $key = hash('sha256', $event['id'].':'.$user['id']);
            $this->records->transaction(function () use ($key, $user, $titles, $event, $url, $payroll) {
                $this->records->lock('notify:'.$key);
                if (! $this->records->find('notifications', $key)) {
                    $this->records->create('notifications', ['user_id' => $user['id'], 'title' => $titles[$event['type']], 'category' => $payroll ? 'payroll' : 'finance', 'url' => $url, 'priority' => 'normal', 'read_at' => null], $key);
                }
                app(NotificationDigest::class)->enqueueInstant($this->records->get('notifications', $key), $this->eventEmail($event, $user, $url, $titles[$event['type']]));
            });
        }
    }

    private function eventEmail(array $event, array $user, string $url, string $message): array
    {
        $payload = $event['payload'];
        $key = match ($event['type']) {
            'estimate.sent' => 'estimate', 'invoice.issued' => 'invoice', 'payment.received' => 'receipt', 'payslip.published' => 'payslip', default => 'message',
        };
        $document = isset($payload['invoice_id']) ? $this->records->find('invoices', $payload['invoice_id']) : (isset($payload['estimate_id']) ? $this->records->find('estimates', $payload['estimate_id']) : null);
        $payment = isset($payload['payment_id']) ? $this->records->find('payments', $payload['payment_id']) : null;
        $currency = $payment['currency'] ?? $document['currency'] ?? null;
        $minor = $payment['amount_minor'] ?? $document['total_minor'] ?? null;
        $variables = [
            'recipient_name' => $user['name'] ?? 'there', 'action_url' => rtrim(config('app.url'), '/').$url,
            'document_number' => $document['number'] ?? $document['title'] ?? '',
            'total' => $currency !== null && $minor !== null ? $currency.' '.(new Money((int) $minor, $currency))->format() : '',
            'message' => $message,
        ];
        if (isset($payload['_email_business_name'])) {
            $variables['business_name'] = $payload['_email_business_name'];
        }

        return ['to' => $user['email'], 'template_key' => $key, 'template_data' => $variables,
            '_template_snapshot' => $payload['_template_snapshot'] ?? app(EmailTemplates::class)->get($key)];
    }

    private function send(array $payload, string $id): void
    {
        $settings = Settings::get('mail');
        $mailer = config('mail.default');
        if (! empty($settings['host'])) {
            config(['mail.mailers.cms' => ['transport' => 'smtp', 'scheme' => ($settings['encryption'] ?? '') === 'ssl' ? 'smtps' : 'smtp', 'host' => $settings['host'], 'port' => $settings['port'], 'username' => $settings['username'] ?? null, 'password' => $settings['password'] ?? null, 'timeout' => 20, 'require_tls' => ($settings['encryption'] ?? '') === 'tls']]);
            Mail::purge('cms');
            $mailer = 'cms';
        }
        Mail::mailer($mailer)->raw(($payload['body'] ?? $payload['text'] ?? ''), function ($message) use ($payload, $settings, $id) {
            $message->to($payload['to'])->subject($payload['subject'])->from($settings['from_address'] ?? config('mail.from.address'), $settings['from_name'] ?? config('mail.from.name'));
            if (! empty($settings['reply_to'])) {
                $message->replyTo($settings['reply_to']);
            }
            $message->getSymfonyMessage()->getHeaders()->addIdHeader('Message-ID', $id.'@'.(parse_url(config('app.url'), PHP_URL_HOST) ?: 'localhost'));
        });
    }

    private function heartbeat(): void
    {
        Settings::put('scheduler', ['last_run_at' => now()->toISOString()]);
    }
}

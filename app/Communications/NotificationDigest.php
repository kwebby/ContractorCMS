<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace App\Communications;

use App\Contracts\RecordRepository;
use App\Support\Outbox;
use App\Support\Settings;
use Illuminate\Support\Carbon;

final class NotificationDigest
{
    public function __construct(private readonly RecordRepository $records) {}

    public function enqueueInstant(array $notification, ?array $emailPayload = null): void
    {
        $settings = Settings::get('notifications');
        if (! ($settings['email_enabled'] ?? true) || ($settings['digest'] ?? 'instant') !== 'instant') {
            return;
        }
        $user = $this->records->find('users', $notification['user_id']);
        if (! $user || ($user['active'] ?? true) === false || empty($user['email_verified_at']) || empty($user['email']) || ($user['notification_preferences']['email_enabled'] ?? true) === false) {
            return;
        }
        $this->records->transaction(function () use ($notification, $user, $emailPayload): void {
            $this->records->lock('notification-email:'.$notification['id']);
            $current = $this->records->get('notifications', $notification['id']);
            if (! empty($current['email_queued_at']) || ! empty($current['read_at'])) {
                return;
            }
            $payload = $emailPayload ?? [
                'to' => $user['email'], 'template_key' => 'message', 'template_data' => [
                    'recipient_name' => $user['name'] ?? 'there',
                    'message' => 'You have a new update in your secure workspace. Sign in to review it.',
                    'action_url' => rtrim(config('app.url'), '/').'/app/notifications',
                ],
            ];
            Outbox::push('email', $payload, 'notification-email:'.$notification['id']);
            $this->records->update('notifications', $notification['id'], ['email_queued_at' => now()->toIso8601String()]);
        });
    }

    public function enqueueDaily(int $limit = 50): int
    {
        $settings = Settings::get('notifications');
        if (empty($settings['email_enabled']) || ($settings['digest'] ?? 'instant') !== 'daily') {
            return 0;
        }
        $business = Settings::get('business');
        $localTime = Carbon::now($business['timezone'] ?? 'UTC');
        if ($localTime->hour < 9) {
            return 0;
        }
        $day = $localTime->toDateString();
        $queued = 0;
        foreach ($this->records->all('users') as $user) {
            if (($user['active'] ?? true) === false || empty($user['email_verified_at']) || empty($user['email']) || ($user['notification_preferences']['email_enabled'] ?? true) === false) {
                continue;
            }
            $id = hash('sha256', $day.'|'.$user['id']);
            $created = $this->records->transaction(function () use ($id, $day, $user, $business): bool {
                $this->records->lock('notification-digest:'.$id);
                if ($this->records->find('notification_digest_runs', $id)) {
                    return false;
                }
                $pending = array_slice(array_values(array_filter($this->records->all('notifications', ['user_id' => $user['id']]), fn (array $notification): bool => empty($notification['read_at']) && empty($notification['email_queued_at']))), 0, 200);
                if (! $pending) {
                    return false;
                }
                $count = count($pending);
                $url = rtrim(config('app.url'), '/').'/app/notifications';
                $name = $business['name'] ?? 'Your contractor team';
                $body = $name." has {$count} new unread ".($count === 1 ? 'update' : 'updates')." for you.\n\nSign in to review your notifications:\n".$url;
                Outbox::push('email', ['to' => $user['email'], 'subject' => 'Your daily updates from '.$name, 'body' => $body], 'notification-digest:'.$id);
                foreach ($pending as $notification) {
                    $this->records->update('notifications', $notification['id'], ['email_queued_at' => now()->toIso8601String(), 'digest_day' => $day]);
                }
                $this->records->create('notification_digest_runs', ['user_id' => $user['id'], 'day' => $day, 'count' => $count], $id);

                return true;
            });
            if ($created && ++$queued >= max(1, $limit)) {
                break;
            }
        }

        return $queued;
    }
}

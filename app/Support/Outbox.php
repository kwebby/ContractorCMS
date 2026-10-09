<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace App\Support;

use App\Communications\EmailTemplates;
use App\Communications\NotificationDigest;
use App\Contracts\RecordRepository;

class Outbox
{
    public static function push(string $type, array $payload, ?string $key = null): array
    {
        $records = app(RecordRepository::class);

        return $records->transaction(function () use ($records, $type, $payload, $key): array {
            if ($key) {
                $records->lock('outbox:'.$key);
                $existing = $records->find('outbox', hash('sha256', $key));
                if ($existing) {
                    return $existing;
                }
            }
            $payload = $type === 'email'
                ? EmailTemplates::snapshot($payload)
                : EmailTemplates::snapshotEvent($type, $payload);

            return $records->create('outbox', ['type' => $type, 'payload' => $payload, 'status' => 'pending', 'attempts' => 0, 'available_at' => now()->toISOString()], $key ? hash('sha256', $key) : null);
        });
    }

    public static function audit(string $action, array $details = []): void
    {
        app(RecordRepository::class)->create('audit', ['action' => $action, 'actor_id' => auth()->id(), 'details' => $details]);
    }

    public static function notify(string $userId, string $title, string $category = 'operations', ?string $url = null, string $priority = 'normal', ?array $emailPayload = null): array
    {
        $records = app(RecordRepository::class);

        return $records->transaction(function () use ($records, $userId, $title, $category, $url, $priority, $emailPayload): array {
            $notification = $records->create('notifications', ['user_id' => $userId, 'title' => $title, 'category' => $category, 'url' => $url, 'priority' => $priority, 'read_at' => null]);
            app(NotificationDigest::class)->enqueueInstant($notification, $emailPayload);

            return $notification;
        });
    }
}

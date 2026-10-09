<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace App\Support;

use App\Contracts\RecordRepository;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Crypt;

class Settings
{
    public static function get(string $group): array
    {
        $record = app(RecordRepository::class)->find('settings', $group) ?? [];
        if (isset($record['encrypted'])) {
            return json_decode(Crypt::decryptString($record['encrypted']), true, 512, JSON_THROW_ON_ERROR);
        }

        return Arr::except($record, ['id', 'version', 'created_at', 'updated_at']);
    }

    public static function put(string $group, array $data): void
    {
        $store = app(RecordRepository::class);
        $data = in_array($group, ['mail', 'payments', 'ai'], true) ? ['encrypted' => Crypt::encryptString(json_encode($data, JSON_THROW_ON_ERROR))] : $data;
        $store->transaction(function () use ($store, $group, $data): void {
            $store->lock('settings:'.$group);
            $store->find('settings', $group) ? $store->update('settings', $group, $data) : $store->create('settings', $data, $group);
        });
    }

    public static function masked(string $group): array
    {
        $data = self::get($group);
        foreach ($data as $key => &$value) {
            if (preg_match('/secret|password|api_key/', $key) && $value) {
                $value = '••••••••';
            }
        }

        return $data;
    }
}

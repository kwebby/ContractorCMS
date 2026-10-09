<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace App\Support;

use Illuminate\Support\Facades\Crypt;

class RecordCursor
{
    public static function decode(?string $cursor, string $collection, array $where): ?array
    {
        if (! $cursor) {
            return null;
        }
        try {
            $value = json_decode(Crypt::decryptString($cursor), true, 8, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            abort(422, 'Invalid pagination cursor.');
        }
        abort_unless(($value['query'] ?? '') === self::query($collection, $where) && isset($value['id'],$value['created_at']), 422, 'Cursor belongs to a different query.');

        return $value;
    }

    public static function result(array $rows, int $limit, string $collection, array $where): array
    {
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $last = end($rows);

        return ['data' => $rows, 'next_cursor' => $more && $last ? Crypt::encryptString(json_encode(['id' => $last['id'], 'created_at' => $last['created_at'], 'query' => self::query($collection, $where)])) : null];
    }

    private static function query(string $collection, array $where): string
    {
        ksort($where);

        return hash('sha256', $collection.json_encode($where));
    }
}

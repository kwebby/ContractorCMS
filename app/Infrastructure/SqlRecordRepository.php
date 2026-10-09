<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace App\Infrastructure;

use App\Contracts\RecordRepository;
use App\Support\RecordCursor;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class SqlRecordRepository implements RecordRepository
{
    private const LOOKUPS = ['email', 'customer_id', 'user_id', 'invoice_id', 'conversation_id', 'status', 'type', 'employee_user_id'];

    private function query(string $collection, array $where = []): Builder
    {
        $query = DB::table('cms_records')->where('collection', $collection);
        foreach ($where as $field => $value) {
            if (in_array($field, self::LOOKUPS, true) && is_string($value)) {
                $query->where('lookup_'.$field, $value);
            }
        }

        return $query->orderBy('created_at')->orderBy('id');
    }

    private function lookups(array $data): array
    {
        $values = [];
        foreach (self::LOOKUPS as $field) {
            $value = $data[$field] ?? null;
            $values['lookup_'.$field] = is_string($value) ? mb_substr($value, 0, 254) : null;
        }

        return $values;
    }

    public function page(string $collection, array $where = [], int $limit = 50, ?string $cursor = null): array
    {
        abort_if(array_diff(array_keys($where), self::LOOKUPS), 422, 'Unsupported pagination filter.');
        foreach ($where as $value) {
            abort_unless(is_string($value), 422, 'Pagination filters must be strings.');
        }
        $limit = max(1, min(100, $limit));
        $after = RecordCursor::decode($cursor, $collection, $where);
        $query = $this->query($collection, $where);
        if ($after) {
            $query->where(function ($query) use ($after) {
                $query->where('created_at', '>', $after['created_at'])->orWhere(function ($query) use ($after) {
                    $query->where('created_at', $after['created_at'])->where('id', '>', $after['id']);
                });
            });
        }
        $rows = $query->limit($limit + 1)->get()->map(fn ($row) => $this->decode($row))->all();

        return RecordCursor::result($rows, $limit, $collection, $where);
    }

    public function all(string $collection, array $where = []): array
    {
        $records = $this->query($collection, $where)->get();

        return $records->map(fn ($row) => $this->decode($row))->filter(function (array $record) use ($where): bool {
            foreach ($where as $key => $value) {
                if (($record[$key] ?? null) !== $value) {
                    return false;
                }
            }

            return true;
        })->values()->all();
    }

    public function find(string $collection, string $id): ?array
    {
        $row = DB::table('cms_records')->where('collection', $collection)->where('id', $id)->first();

        return $row ? $this->decode($row) : null;
    }

    public function get(string $collection, string $id): array
    {
        return $this->find($collection, $id) ?? abort(404, 'Record not found.');
    }

    public function create(string $collection, array $data, ?string $id = null): array
    {
        $id ??= (string) Str::uuid();
        unset($data['id'], $data['version'], $data['created_at'], $data['updated_at']);
        $now = now()->toISOString();
        DB::table('cms_records')->insert(['collection' => $collection, 'id' => $id, 'data' => json_encode($data, JSON_THROW_ON_ERROR), 'version' => 1, 'created_at' => $now, 'updated_at' => $now] + $this->lookups($data));

        return $this->get($collection, $id);
    }

    public function update(string $collection, string $id, array $data, ?int $expectedVersion = null): array
    {
        return $this->transaction(function () use ($collection, $id, $data, $expectedVersion): array {
            $row = DB::table('cms_records')->where('collection', $collection)->where('id', $id)->lockForUpdate()->first();
            abort_unless($row, 404);
            if ($expectedVersion !== null && $row->version !== $expectedVersion) {
                throw new ConflictHttpException('This record changed. Reload before saving.');
            }
            $merged = array_replace(json_decode($row->data, true, 512, JSON_THROW_ON_ERROR), $data);
            unset($merged['id'], $merged['version'], $merged['created_at'], $merged['updated_at']);
            DB::table('cms_records')->where('collection', $collection)->where('id', $id)->update(['data' => json_encode($merged, JSON_THROW_ON_ERROR), 'version' => $row->version + 1, 'updated_at' => now()->toISOString()] + $this->lookups($merged));

            return $this->get($collection, $id);
        });
    }

    public function delete(string $collection, string $id): void
    {
        DB::table('cms_records')->where('collection', $collection)->where('id', $id)->delete();
    }

    public function transaction(callable $callback): mixed
    {
        return DB::transaction($callback, 5);
    }

    public function lock(string $key): void
    {
        $key = hash('sha256', $key);
        DB::table('cms_locks')->insertOrIgnore(['key' => $key, 'value' => 0]);
        DB::table('cms_locks')->where('key', $key)->lockForUpdate()->first();
    }

    public function sequence(string $key): int
    {
        return $this->transaction(function () use ($key): int {
            $this->lock($key);
            $key = hash('sha256', $key);
            DB::table('cms_locks')->where('key', $key)->increment('value');

            return (int) DB::table('cms_locks')->where('key', $key)->value('value');
        });
    }

    private function decode(object $row): array
    {
        return array_merge(json_decode($row->data, true, 512, JSON_THROW_ON_ERROR), ['id' => $row->id, 'version' => (int) $row->version, 'created_at' => $row->created_at, 'updated_at' => $row->updated_at]);
    }
}

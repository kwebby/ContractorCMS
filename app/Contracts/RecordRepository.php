<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace App\Contracts;

interface RecordRepository
{
    public function all(string $collection, array $where = []): array;

    public function page(string $collection, array $where = [], int $limit = 50, ?string $cursor = null): array;

    public function find(string $collection, string $id): ?array;

    public function get(string $collection, string $id): array;

    public function create(string $collection, array $data, ?string $id = null): array;

    public function update(string $collection, string $id, array $data, ?int $expectedVersion = null): array;

    public function delete(string $collection, string $id): void;

    public function transaction(callable $callback): mixed;

    public function lock(string $key): void;

    public function sequence(string $key): int;
}

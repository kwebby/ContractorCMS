<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace App\Infrastructure;

use App\Contracts\RecordRepository;
use App\Support\RecordCursor;
use Google\Auth\Credentials\ServiceAccountCredentials;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class FirestoreRecordRepository implements RecordRepository
{
    private ?string $activeTransaction = null;

    private array $reads = [];

    private array $writes = [];

    private ?string $accessToken = null;

    private int $tokenExpiresAt = 0;

    public function page(string $collection, array $where = [], int $limit = 50, ?string $cursor = null): array
    {
        $limit = max(1, min(100, $limit));
        $after = RecordCursor::decode($cursor, $collection, $where);
        $rows = $this->all($collection, $where);
        if ($after) {
            $rows = array_values(array_filter($rows, fn ($row) => [$row['created_at'], $row['id']] > [$after['created_at'], $after['id']]));
        }

        return RecordCursor::result($rows, $limit, $collection, $where);
    }

    public function all(string $collection, array $where = []): array
    {
        $this->validateCollection($collection);
        $records = [];
        $cursor = null;
        do {
            $query = ['from' => [['collectionId' => $collection]], 'orderBy' => [['field' => ['fieldPath' => '__name__'], 'direction' => 'ASCENDING']], 'limit' => 500];
            if ($cursor !== null) {
                $query['startAt'] = ['values' => [['referenceValue' => $cursor]], 'before' => false];
            }
            $body = ['structuredQuery' => $query];
            if ($this->activeTransaction !== null) {
                $body['transaction'] = $this->activeTransaction;
            }
            $rows = $this->http()->post($this->documentUrl().':runQuery', $body)->throw()->json();
            $documents = array_values(array_filter($rows, fn (array $row) => isset($row['document'])));
            foreach ($documents as $row) {
                $document = $row['document'];
                $id = rawurldecode(basename($document['name']));
                $record = $this->decodeDocument($document, $id);
                $records[$id] = $record;
                if ($this->activeTransaction !== null && ! array_key_exists($collection.'/'.$id, $this->reads)) {
                    $this->reads[$collection.'/'.$id] = ['record' => $record, 'update_time' => $document['updateTime'] ?? null];
                }
            }
            $nextCursor = count($documents) > 0 ? $documents[array_key_last($documents)]['document']['name'] : null;
            if (count($documents) === 500 && $nextCursor === $cursor) {
                throw new \RuntimeException('Firestore query cursor did not advance.');
            }
            $cursor = $nextCursor;
        } while (count($documents) === 500);
        foreach ($this->writes as $key => $write) {
            if (str_starts_with($key, $collection.'/')) {
                $id = substr($key, strlen($collection) + 1);
                if ($write === null) {
                    unset($records[$id]);
                } else {
                    $records[$id] = $write;
                }
            }
        }
        $records = array_values(array_filter($records, function (array $record) use ($where): bool {
            foreach ($where as $key => $value) {
                if (($record[$key] ?? null) !== $value) {
                    return false;
                }
            }

            return true;
        }));
        usort($records, fn (array $left, array $right) => [$left['created_at'], $left['id']] <=> [$right['created_at'], $right['id']]);

        return $records;
    }

    public function find(string $collection, string $id): ?array
    {
        $this->validatePath($collection, $id);
        $key = $collection.'/'.$id;
        if ($this->activeTransaction !== null) {
            if (array_key_exists($key, $this->writes)) {
                return $this->writes[$key];
            }
            if (array_key_exists($key, $this->reads)) {
                return $this->reads[$key]['record'];
            }
        }
        $response = $this->http()->get($this->documentUrl().'/'.rawurlencode($collection).'/'.rawurlencode($id), $this->activeTransaction !== null ? ['transaction' => $this->activeTransaction] : []);
        if ($response->status() === 404) {
            if ($this->activeTransaction !== null) {
                $this->reads[$key] = ['record' => null, 'update_time' => null];
            }

            return null;
        }
        $document = $response->throw()->json();
        $record = $this->decodeDocument($document, $id);
        if ($this->activeTransaction !== null) {
            $this->reads[$key] = ['record' => $record, 'update_time' => $document['updateTime'] ?? null];
        }

        return $record;
    }

    public function get(string $collection, string $id): array
    {
        return $this->find($collection, $id) ?? abort(404, 'Record not found.');
    }

    public function create(string $collection, array $data, ?string $id = null): array
    {
        $id ??= (string) Str::uuid();

        return $this->transaction(function () use ($collection, $data, $id) {
            $this->validatePath($collection, $id);
            if ($this->find($collection, $id) !== null) {
                throw new ConflictHttpException('A record with this identifier already exists.');
            }
            unset($data['id'], $data['version'], $data['created_at'], $data['updated_at']);
            $record = array_merge($data, ['id' => $id, 'version' => 1, 'created_at' => now()->toISOString(), 'updated_at' => now()->toISOString()]);
            $this->writes[$collection.'/'.$id] = $record;

            return $record;
        });
    }

    public function update(string $collection, string $id, array $data, ?int $expectedVersion = null): array
    {
        return $this->transaction(function () use ($collection, $id, $data, $expectedVersion) {
            $record = $this->get($collection, $id);
            if ($expectedVersion !== null && $record['version'] !== $expectedVersion) {
                throw new ConflictHttpException('This record changed. Reload before saving.');
            }
            unset($data['id'], $data['version'], $data['created_at'], $data['updated_at']);
            $record = array_merge($record, $data, ['version' => $record['version'] + 1, 'updated_at' => now()->toISOString()]);
            $this->writes[$collection.'/'.$id] = $record;

            return $record;
        });
    }

    public function delete(string $collection, string $id): void
    {
        $this->transaction(function () use ($collection, $id) {
            if ($this->find($collection, $id) !== null) {
                $this->writes[$collection.'/'.$id] = null;
            }
        });
    }

    public function transaction(callable $callback): mixed
    {
        if ($this->activeTransaction !== null) {
            $savepoint = $this->writes;
            try {
                return $callback();
            } catch (\Throwable $exception) {
                $this->writes = $savepoint;
                throw $exception;
            }
        }
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->reads = $this->writes = [];
            $begin = $this->http()->post($this->documentUrl().':beginTransaction', ['options' => ['readWrite' => new \stdClass]])->throw()->json();
            $this->activeTransaction = $begin['transaction'];
            try {
                $result = $callback();
                $writes = $this->compileWrites();
                $this->http()->post($this->documentUrl().':commit', ['transaction' => $this->activeTransaction, 'writes' => $writes])->throw();

                return $result;
            } catch (\Throwable $exception) {
                $this->rollbackQuietly();
                $retry = $exception instanceof RequestException && $exception->response->json('error.status') === 'ABORTED';
                if (! $retry || $attempt === 4) {
                    throw $exception;
                }
            } finally {
                $this->activeTransaction = null;
                $this->reads = $this->writes = [];
            }
            usleep(random_int(10000, 40000) * ($attempt + 1));
        }
        throw new \RuntimeException('Firestore transaction retry limit exceeded.');
    }

    public function lock(string $key): void
    {
        if ($this->activeTransaction === null) {
            throw new \LogicException('Locks must be acquired inside a transaction.');
        }
        $id = hash('sha256', $key);
        $record = $this->find('_cms_locks', $id);
        if ($record === null) {
            $this->create('_cms_locks', ['value' => 0, 'lock_tick' => 1], $id);
        } else {
            $this->update('_cms_locks', $id, ['lock_tick' => ($record['lock_tick'] ?? 0) + 1]);
        }
    }

    public function sequence(string $key): int
    {
        return $this->transaction(function () use ($key) {
            $this->lock($key);
            $id = hash('sha256', $key);
            $record = $this->get('_cms_locks', $id);
            $next = $record['value'] + 1;
            $this->update('_cms_locks', $id, ['value' => $next]);

            return $next;
        });
    }

    private function compileWrites(): array
    {
        if (count($this->writes) > 500) {
            throw new \LengthException('Firestore transactions support at most 500 documents. Split this operation into smaller batches.');
        }
        $compiled = [];
        foreach ($this->writes as $key => $record) {
            $name = $this->documentName().'/'.$key;
            $original = $this->reads[$key] ?? ['record' => null, 'update_time' => null];
            $precondition = $original['record'] === null ? ['exists' => false] : ($original['update_time'] !== null ? ['updateTime' => $original['update_time']] : ['exists' => true]);
            if ($record === null) {
                if ($original['record'] !== null) {
                    $compiled[] = ['delete' => $name, 'currentDocument' => $precondition];
                }

                continue;
            }
            unset($record['id']);
            $fields = [];
            foreach ($record as $field => $value) {
                $fields[$field] = $this->encodeValue($value);
            }
            $write = ['update' => ['name' => $name, 'fields' => (object) $fields], 'currentDocument' => $precondition];
            if (strlen(json_encode($write, JSON_THROW_ON_ERROR)) > 950000) {
                throw new \LengthException('This record exceeds the Firestore document size limit.');
            }
            $compiled[] = $write;
        }

        if (strlen(json_encode($compiled, JSON_THROW_ON_ERROR)) > 9000000) {
            throw new \LengthException('Firestore transaction exceeds its request size limit.');
        }

        return $compiled;
    }

    private function encodeValue(mixed $value): array
    {
        if ($value === null) {
            return ['nullValue' => null];
        }
        if (is_bool($value)) {
            return ['booleanValue' => $value];
        }
        if (is_int($value)) {
            return ['integerValue' => (string) $value];
        }
        if (is_float($value) && is_finite($value)) {
            return ['doubleValue' => $value];
        }
        if (is_string($value)) {
            return ['stringValue' => $value];
        }
        if (is_array($value)) {
            if (array_is_list($value)) {
                return ['mapValue' => ['fields' => ['_cms_type' => ['stringValue' => 'list'], '_cms_values' => ['arrayValue' => ['values' => array_map(fn (mixed $item) => $this->encodeValue($item), $value)]]]]];
            }
            $fields = [];
            foreach ($value as $key => $item) {
                $fields[$key] = $this->encodeValue($item);
            }

            return ['mapValue' => ['fields' => ['_cms_type' => ['stringValue' => 'map'], '_cms_values' => ['mapValue' => ['fields' => (object) $fields]]]]];
        }
        throw new \InvalidArgumentException('Firestore records support only JSON-compatible finite values.');
    }

    private function decodeValue(array $value): mixed
    {
        if (array_key_exists('nullValue', $value)) {
            return null;
        }
        if (array_key_exists('integerValue', $value)) {
            return (int) $value['integerValue'];
        }
        if (array_key_exists('booleanValue', $value)) {
            return $value['booleanValue'];
        }
        if (array_key_exists('doubleValue', $value)) {
            return (float) $value['doubleValue'];
        }
        if (array_key_exists('stringValue', $value)) {
            return $value['stringValue'];
        }
        if (array_key_exists('timestampValue', $value)) {
            return $value['timestampValue'];
        }
        if (array_key_exists('arrayValue', $value)) {
            return array_map(fn (array $item) => $this->decodeValue($item), $value['arrayValue']['values'] ?? []);
        }
        if (array_key_exists('mapValue', $value)) {
            $type = $value['mapValue']['fields']['_cms_type']['stringValue'] ?? null;
            if ($type === 'list') {
                return array_map(fn (array $item) => $this->decodeValue($item), $value['mapValue']['fields']['_cms_values']['arrayValue']['values'] ?? []);
            }
            if ($type === 'map') {
                return array_map(fn (array $item) => $this->decodeValue($item), $value['mapValue']['fields']['_cms_values']['mapValue']['fields'] ?? []);
            }

            return array_map(fn (array $item) => $this->decodeValue($item), $value['mapValue']['fields'] ?? []);
        }
        throw new \UnexpectedValueException('Unsupported Firestore field type.');
    }

    private function decodeDocument(array $document, string $id): array
    {
        $record = [];
        foreach ($document['fields'] ?? [] as $key => $value) {
            $record[$key] = $this->decodeValue($value);
        }

        return array_merge($record, ['id' => $id, 'version' => (int) ($record['version'] ?? 1), 'created_at' => $record['created_at'] ?? $document['createTime'] ?? '', 'updated_at' => $record['updated_at'] ?? $document['updateTime'] ?? '']);
    }

    private function http(): PendingRequest
    {
        $request = Http::acceptJson()->asJson()->connectTimeout(5)->timeout(30);
        if (config('cms.firestore.emulator')) {
            return $request;
        }
        if ($this->accessToken === null || $this->tokenExpiresAt <= time() + 60) {
            $path = config('cms.firestore.credentials');
            if (! is_string($path) || ! is_file($path) || ! is_readable($path)) {
                throw new \RuntimeException('Set GOOGLE_APPLICATION_CREDENTIALS to a readable private service-account JSON file.');
            }
            $key = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            if (($key['type'] ?? '') !== 'service_account') {
                throw new \RuntimeException('Firestore requires a service-account credential file.');
            }
            $credentials = new ServiceAccountCredentials('https://www.googleapis.com/auth/datastore', $key);
            $token = $credentials->fetchAuthToken();
            $this->accessToken = $token['access_token'] ?? throw new \RuntimeException('Unable to authenticate to Firestore.');
            $this->tokenExpiresAt = time() + (int) ($token['expires_in'] ?? 3600);
        }

        return $request->withToken($this->accessToken);
    }

    private function documentName(): string
    {
        $project = config('cms.firestore.project');
        $database = config('cms.firestore.database', '(default)');
        if (! is_string($project) || ! preg_match('/^[a-zA-Z0-9-]{3,100}$/D', $project) || ! is_string($database) || ! preg_match('/^(?:\(default\)|[a-zA-Z0-9-]{1,100})$/D', $database)) {
            throw new \RuntimeException('Set a valid Firestore project and database identifier.');
        }

        return 'projects/'.$project.'/databases/'.$database.'/documents';
    }

    private function documentUrl(): string
    {
        $host = 'https://firestore.googleapis.com';
        if ($emulator = config('cms.firestore.emulator')) {
            if (! is_string($emulator) || ! preg_match('/^[a-zA-Z0-9.\[\]:_-]+:\d{1,5}$/D', $emulator)) {
                throw new \RuntimeException('Firestore emulator must be a hostname and port without a URL scheme or path.');
            }
            $host = 'http://'.$emulator;
        }

        return $host.'/v1/'.$this->documentName();
    }

    private function rollbackQuietly(): void
    {
        if ($this->activeTransaction !== null) {
            try {
                $this->http()->post($this->documentUrl().':rollback', ['transaction' => $this->activeTransaction]);
            } catch (\Throwable) {
            }
        }
    }

    private function validateCollection(string $collection): void
    {
        if (! preg_match('/^[_a-zA-Z][a-zA-Z0-9_-]{0,99}$/D', $collection)) {
            throw new \InvalidArgumentException('Invalid collection identifier.');
        }
    }

    private function validatePath(string $collection, string $id): void
    {
        $this->validateCollection($collection);
        if ($id === '' || strlen($id) > 500 || preg_match('/[\x00-\x1f\/]/', $id) || $id === '.' || $id === '..') {
            throw new \InvalidArgumentException('Invalid record identifier.');
        }
    }
}

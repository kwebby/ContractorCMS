<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace Tests\Feature;

use App\Contracts\RecordRepository;
use App\Finance\FinanceService;
use App\Identity\CmsUser;
use App\Identity\RepositoryUserProvider;
use App\Infrastructure\FirestoreRecordRepository;
use App\Support\Access;
use App\Support\Settings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class FirestoreRepositoryTest extends TestCase
{
    private FirestoreRecordRepository $records;

    private array $documents = [];

    private int $begins = 0;

    private int $commits = 0;

    private int $rollbacks = 0;

    private bool $abortNextCommit = false;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'no-sql-connection-exists', 'cms.firestore.project' => 'contractor-test', 'cms.firestore.database' => '(default)', 'cms.firestore.emulator' => '127.0.0.1:8099']);
        $this->records = new FirestoreRecordRepository;
        $this->app->instance(RecordRepository::class, $this->records);
        $this->actingAs(new CmsUser(['id' => 'owner', 'name' => 'Owner', 'role' => 'owner']));
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            $url = $request->url();
            if (str_ends_with($url, ':beginTransaction')) {
                $this->begins++;

                return Http::response(['transaction' => base64_encode('transaction-'.$this->begins)]);
            }
            if (str_ends_with($url, ':rollback')) {
                $this->rollbacks++;

                return Http::response([]);
            }
            if (str_ends_with($url, ':commit')) {
                $this->commits++;
                if ($this->abortNextCommit) {
                    $this->abortNextCommit = false;

                    return Http::response(['error' => ['status' => 'ABORTED']], 409);
                }
                foreach ($request['writes'] as $write) {
                    $name = $write['update']['name'] ?? $write['delete'];
                    if (($write['currentDocument']['exists'] ?? null) === false) {
                        $this->assertArrayNotHasKey($name, $this->documents, 'Create must have an existence precondition.');
                    }
                    if (isset($write['currentDocument']['updateTime'])) {
                        $this->assertSame($this->documents[$name]['updateTime'], $write['currentDocument']['updateTime']);
                    }
                }
                foreach ($request['writes'] as $write) {
                    if (isset($write['delete'])) {
                        unset($this->documents[$write['delete']]);
                    } else {
                        $document = $write['update'];
                        $document['updateTime'] = '2026-10-09T00:00:'.str_pad((string) $this->commits, 2, '0', STR_PAD_LEFT).'Z';
                        $this->documents[$document['name']] = $document;
                    }
                }

                return Http::response(['commitTime' => '2026-10-09T00:00:00Z']);
            }
            if (str_ends_with($url, ':runQuery')) {
                $collection = $request['structuredQuery']['from'][0]['collectionId'];
                $cursor = $request['structuredQuery']['startAt']['values'][0]['referenceValue'] ?? '';
                $prefix = 'projects/contractor-test/databases/(default)/documents/'.$collection.'/';
                $documents = array_filter($this->documents, fn (array $document) => str_starts_with($document['name'], $prefix) && $document['name'] > $cursor);
                ksort($documents);
                $documents = array_slice($documents, 0, $request['structuredQuery']['limit']);

                return Http::response(array_values(array_map(fn (array $document) => ['document' => $document], $documents)));
            }
            if ($request->method() === 'GET') {
                $path = rawurldecode(parse_url($url, PHP_URL_PATH));
                $name = substr($path, strlen('/v1/'));

                return isset($this->documents[$name]) ? Http::response($this->documents[$name]) : Http::response(['error' => ['status' => 'NOT_FOUND']], 404);
            }
            $this->fail('Unexpected Firestore request: '.$request->method().' '.$url);
        });
    }

    public function test_crud_round_trips_nested_blocknote_and_integer_money_without_sql(): void
    {
        $nested = ['items' => [['description' => 'Labor', 'amount' => 12345]], 'table' => [[['text' => 'A']], [['text' => 'B']]], 'empty' => [], 'nullable' => null, 'flags' => [true, false], '_cms_type' => 'user-defined-key'];
        $created = $this->records->create('invoices', ['payload' => $nested, 'amount_minor' => 99999999999], 'invoice-1');
        $this->assertSame(1, $created['version']);
        $stored = $this->records->get('invoices', 'invoice-1');
        $this->assertSame($nested, $stored['payload']);
        $this->assertSame(99999999999, $stored['amount_minor']);
        $updated = $this->records->update('invoices', 'invoice-1', ['amount_minor' => 1234, 'id' => 'forged'], 1);
        $this->assertSame('invoice-1', $updated['id']);
        $this->assertSame(2, $updated['version']);
        $this->records->delete('invoices', 'invoice-1');
        $this->assertNull($this->records->find('invoices', 'invoice-1'));
    }

    public function test_read_your_writes_and_nested_savepoint_rollback(): void
    {
        $this->records->transaction(function () {
            $this->records->create('jobs', ['status' => 'approved'], 'keep');
            $this->assertSame('approved', $this->records->get('jobs', 'keep')['status']);
            try {
                $this->records->transaction(function () {
                    $this->records->create('jobs', ['status' => 'draft'], 'discard');
                    $this->records->update('jobs', 'keep', ['status' => 'changed']);
                    throw new \RuntimeException('Rollback nested work');
                });
            } catch (\RuntimeException) {
            }
            $this->assertSame('approved', $this->records->get('jobs', 'keep')['status']);
            $this->assertNull($this->records->find('jobs', 'discard'));
            $this->assertCount(1, $this->records->all('jobs', ['status' => 'approved']));
        });
        $this->assertSame(1, $this->commits);
        $this->assertSame(1, $this->begins);
        $this->assertCount(1, $this->records->all('jobs'));
    }

    public function test_stale_version_rolls_back_without_overwriting(): void
    {
        $this->records->create('jobs', ['title' => 'Original'], 'job-1');
        $this->records->update('jobs', 'job-1', ['title' => 'Current'], 1);
        try {
            $this->records->update('jobs', 'job-1', ['title' => 'Stale'], 1);
            $this->fail('Expected a version conflict.');
        } catch (ConflictHttpException) {
            $this->assertSame('Current', $this->records->get('jobs', 'job-1')['title']);
            $this->assertSame(1, $this->rollbacks);
        }
    }

    public function test_aborted_transaction_retries_and_sequence_remains_atomic(): void
    {
        $this->abortNextCommit = true;
        $executions = 0;
        $number = $this->records->transaction(function () use (&$executions) {
            $executions++;
            $next = $this->records->sequence('invoice-number');
            $this->records->create('invoices', ['number' => $next], 'invoice-one');

            return $next;
        });
        $this->assertSame(2, $executions);
        $this->assertSame(1, $number);
        $this->assertSame(2, $this->records->sequence('invoice-number'));
        $this->assertCount(1, $this->records->all('invoices'));
        $this->assertSame(1, $this->rollbacks);
    }

    public function test_query_pages_all_documents_and_keeps_filter_semantics(): void
    {
        for ($i = 0; $i < 501; $i++) {
            $id = 'job-'.str_pad((string) $i, 4, '0', STR_PAD_LEFT);
            $name = 'projects/contractor-test/databases/(default)/documents/jobs/'.$id;
            $this->documents[$name] = ['name' => $name, 'fields' => ['status' => ['stringValue' => $i % 2 === 0 ? 'open' : 'closed'], 'version' => ['integerValue' => '1'], 'created_at' => ['stringValue' => '2026-01-01'], 'updated_at' => ['stringValue' => '2026-01-01']]];
        }
        $this->assertCount(251, $this->records->all('jobs', ['status' => 'open']));
        $this->assertCount(501, $this->records->all('jobs', ['missing' => null]));
    }

    public function test_complete_invoice_workflow_has_no_sql_dependency(): void
    {
        Settings::put('business', ['legal_name' => 'Firestore Plumbing']);
        $customer = $this->records->create('customers', ['name' => 'Firestore Client', 'email' => 'client@example.test']);
        $finance = new FinanceService($this->records);
        $draft = $finance->draft('invoices', ['customer_id' => $customer['id'], 'title' => 'Drain repair', 'currency' => 'CAD', 'items' => [['description' => 'Service visit', 'quantity' => '1', 'unit_price_minor' => 15000, 'tax_basis_points' => 1300]]]);
        $invoice = $finance->issue($draft['id']);
        $finance->payment($invoice['id'], 10000, 'cash', 'Offline receipt', 'firestore-payment');
        $finance->payment($invoice['id'], 10000, 'cash', 'Offline receipt', 'firestore-payment');
        $this->assertSame('INV-000001', $invoice['number']);
        $this->assertSame(16950, $invoice['total_minor']);
        $this->assertSame(6950, $finance->balance($invoice)['balance_minor']);
        $this->assertCount(1, $this->records->all('payments'));
        $this->assertCount(2, $this->records->all('outbox'));
    }

    public function test_outer_failure_discards_all_buffered_writes(): void
    {
        try {
            $this->records->transaction(function () {
                $this->records->create('users', ['email' => 'temporary@example.test'], 'temporary');
                throw new \RuntimeException('Abort transaction');
            });
        } catch (\RuntimeException) {
        }
        $this->assertNull($this->records->find('users', 'temporary'));
        $this->assertSame(0, $this->commits);
        $this->assertSame(1, $this->rollbacks);
        $this->records->create('users', ['email' => 'next@example.test'], 'next');
        $this->assertSame('next', $this->records->get('users', 'next')['id']);
    }

    public function test_authentication_and_permissions_use_firestore_records_without_sql(): void
    {
        $user = $this->records->create('users', ['email' => 'tech@example.test', 'name' => 'Taylor Tech', 'role' => 'technician', 'active' => true, 'password' => Hash::make('Correct Horse Battery Staple')]);
        $provider = new RepositoryUserProvider($this->records);
        $authenticated = $provider->retrieveByCredentials(['email' => 'TECH@example.test']);
        $this->assertNotNull($authenticated);
        $this->assertTrue($provider->validateCredentials($authenticated, ['password' => 'Correct Horse Battery Staple']));
        $this->assertFalse($provider->validateCredentials($authenticated, ['password' => 'wrong-password']));
        $this->assertTrue(Access::can($authenticated, 'operations.assigned'));
        $this->assertFalse(Access::can($authenticated, 'payroll.manage'));
        $this->records->update('users', $user['id'], ['active' => false]);
        $this->assertNull($provider->retrieveById($user['id']));
    }
}

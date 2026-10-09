<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace Tests\Integration;

use App\Contracts\RecordRepository;
use App\Finance\FinanceService;
use App\Support\Settings;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class SqlConcurrencyTest extends TestCase
{
    public function test_parallel_invoice_issue_and_payment_replays_do_not_duplicate_money_or_numbers(): void
    {
        $driver = config('database.default');
        if (! in_array($driver, ['pgsql', 'mysql'], true)) {
            $this->markTestSkipped('Run explicitly against the dedicated PostgreSQL or MySQL test database.');
        }
        $connection = config('database.connections.'.$driver);
        $this->assertStringContainsString('test', $connection['database'], 'Concurrency tests require an explicitly named test database.');
        Artisan::call('migrate:fresh', ['--force' => true]);
        $records = app(RecordRepository::class);
        $finance = app(FinanceService::class);
        Settings::put('business', ['name' => 'Concurrency Test', 'legal_name' => 'Concurrency Test']);
        $customer = $records->create('customers', ['name' => 'Test customer']);
        $drafts = [];
        for ($i = 0; $i < 3; $i++) {
            $drafts[] = $finance->draft('invoices', ['customer_id' => $customer['id'], 'title' => 'Test', 'currency' => 'USD', 'items' => [['description' => 'Test', 'quantity' => '1', 'unit_price_minor' => 10000]]]);
        }
        $environment = ['APP_ENV' => 'testing', 'APP_URL' => 'http://localhost', 'APP_KEY' => config('app.key'), 'CMS_MFA_REQUIRED' => 'false', 'DB_URL' => '', 'DB_CONNECTION' => $driver, 'DB_HOST' => $connection['host'], 'DB_PORT' => (string) $connection['port'], 'DB_DATABASE' => $connection['database'], 'DB_USERNAME' => $connection['username'], 'DB_PASSWORD' => $connection['password'] ?? '', 'DATA_PROVIDER' => $driver === 'mysql' ? 'mysql' : 'postgres', 'MAIL_MAILER' => 'array', 'SESSION_DRIVER' => 'array', 'CACHE_STORE' => 'array'];
        $code = 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); $service=$app->make(App\Finance\FinanceService::class); $invoice=$service->issue($argv[1]); $service->payment($argv[1],5000,"cash","Concurrent test","concurrent:".$argv[1]); echo $invoice["number"];';
        $processes = [];
        foreach ([$drafts[0], $drafts[0], $drafts[1], $drafts[2]] as $draft) {
            $process = new Process([PHP_BINARY, '-r', $code, $draft['id']], base_path(), $environment, null, 30);
            $process->start();
            $processes[] = $process;
        }
        foreach ($processes as $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
        }
        $invoices = $records->all('invoices');
        $this->assertCount(3, array_unique(array_column($invoices, 'number')));
        $this->assertCount(3, $records->all('payments'));
        foreach ($invoices as $invoice) {
            $this->assertSame(5000, $finance->balance($invoice)['balance_minor']);
        }
        $this->assertSame($processes[0]->getOutput(), $processes[1]->getOutput());
    }
}

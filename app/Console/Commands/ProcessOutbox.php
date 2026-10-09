<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace App\Console\Commands;

use App\Communications\NotificationDigest;
use App\Infrastructure\OutboxProcessor;
use Illuminate\Console\Command;

class ProcessOutbox extends Command
{
    protected $signature = 'cms:outbox {--limit=50} {--seconds=45}';

    protected $description = 'Process durable notifications and email with bounded leases and retries';

    public function handle(OutboxProcessor $processor): int
    {
        if (! app()->isDownForMaintenance()) {
            app(NotificationDigest::class)->enqueueDaily();
        }
        $result = $processor->run(max(1, min(500, (int) $this->option('limit'))), max(1, min(50, (int) $this->option('seconds'))));
        $this->info(json_encode($result));

        return $result['failed'] ? self::FAILURE : self::SUCCESS;
    }
}

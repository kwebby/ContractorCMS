<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace App\Console\Commands;

use App\Infrastructure\BackupService;
use Illuminate\Console\Command;

class BackupCms extends Command
{
    protected $signature = 'cms:backup';

    protected $description = 'Create an encrypted database and local-files backup while in maintenance mode';

    public function handle(BackupService $service): int
    {
        try {
            $this->info($service->create(getenv('CMS_BACKUP_PASSWORD') ?: ''));

            return self::SUCCESS;
        } catch (\Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }
}

<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace App\Console\Commands;

use App\Infrastructure\BackupService;
use Illuminate\Console\Command;

class RestoreCms extends Command
{
    protected $signature = 'cms:restore {archive : Absolute encrypted backup path}';

    protected $description = 'Restore into an empty migrated database and empty private storage during maintenance';

    public function handle(BackupService $service): int
    {
        try {
            $service->restore($this->argument('archive'), getenv('CMS_BACKUP_PASSWORD') ?: '');
            $this->info('Restored. Run cms:doctor and verify content before leaving maintenance mode.');

            return self::SUCCESS;
        } catch (\Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }
}

<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace App\Console\Commands;

use App\Automation\LeadToolService;
use Illuminate\Console\Command;

class PruneCms extends Command
{
    protected $signature = 'cms:prune';

    protected $description = 'Delete expired planning-tool input, report requests and confirmation tokens';

    public function handle(LeadToolService $tools): int
    {
        if (app()->isDownForMaintenance()) {
            return self::SUCCESS;
        }
        $this->info(json_encode($tools->purgeExpired()));

        return self::SUCCESS;
    }
}

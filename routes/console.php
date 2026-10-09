<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

use Illuminate\Support\Facades\Schedule;

Schedule::command('cms:outbox --limit=50 --seconds=45')->everyMinute()->withoutOverlapping(5);

<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->command?->info('No default production seed data. Run cms:install to create the owner, or explicitly choose DemoSeeder in a local/testing environment.');
    }
}

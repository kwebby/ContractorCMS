<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace Tests\Feature;

use App\Contracts\RecordRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstallerTest extends TestCase
{
    use RefreshDatabase;

    public function test_initial_owner_is_created_once_and_bootstrap_stays_locked(): void
    {
        if (! in_array(config('database.default'), ['pgsql', 'mysql'], true)) {
            $this->markTestSkipped('Installer certification requires the real SQL profile.');
        }
        config(['cms.data_provider' => config('database.default') === 'mysql' ? 'mysql' : 'postgres']);
        putenv('CMS_OWNER_PASSWORD=long-test-owner-password');
        try {
            $args = ['--email' => 'owner@example.test', '--name' => 'Owner', '--business' => 'Example Trades', '--country' => 'CA', '--currency' => 'CAD'];
            $this->artisan('cms:install', $args)->assertSuccessful();
            $r = app(RecordRepository::class);
            $this->assertSame('owner', $r->all('users')[0]['role']);
            $this->assertTrue($r->get('settings', 'installation')['bootstrap_locked']);
            $this->artisan('cms:install', $args)->assertFailed();
            $this->assertCount(1, $r->all('users'));
        } finally {
            putenv('CMS_OWNER_PASSWORD');
        }
    }
}

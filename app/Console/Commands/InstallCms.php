<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace App\Console\Commands;

use App\Contracts\RecordRepository;
use App\Support\Settings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class InstallCms extends Command
{
    protected $signature = 'cms:install {--email=} {--name=} {--business=} {--country=US} {--currency=USD}';

    protected $description = 'Create the initial owner once and permanently lock bootstrap access';

    public function handle(RecordRepository $records): int
    {
        if ($this->call('cms:doctor', ['--install' => true]) !== 0) {
            return self::FAILURE;
        }
        if ($records->find('settings', 'installation') || count($records->all('users'))) {
            $this->error('Installation is already locked.');

            return self::FAILURE;
        }
        $email = strtolower($this->option('email') ?: $this->ask('Owner email'));
        $name = $this->option('name') ?: $this->ask('Owner name');
        $business = $this->option('business') ?: $this->ask('Business name');
        $password = getenv('CMS_OWNER_PASSWORD') ?: $this->secret('Owner password (at least 12 characters)');
        $values = ['email' => $email, 'name' => $name, 'business' => $business, 'password' => $password, 'country' => $this->option('country'), 'currency' => $this->option('currency')];
        $validator = Validator::make($values, ['email' => 'required|email|max:254', 'name' => 'required|string|max:120', 'business' => 'required|string|max:160', 'password' => 'required|string|min:12', 'country' => 'in:US,CA', 'currency' => 'in:USD,CAD']);
        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }
        $records->transaction(function () use ($records, $values) {
            $records->lock('installation');
            if ($records->find('settings', 'installation') || count($records->all('users'))) {
                throw new \RuntimeException('Bootstrap already locked.');
            }
            $records->create('users', ['name' => $values['name'], 'email' => $values['email'], 'password' => Hash::make($values['password']), 'role' => 'owner', 'active' => true, 'email_verified_at' => now()->toISOString(), 'customer_id' => null, 'session_version' => 1]);
            Settings::put('business', ['name' => $values['business'], 'legal_name' => $values['business'], 'email' => $values['email'], 'country' => $values['country'], 'currency' => $values['currency'], 'timezone' => 'America/New_York']);
            Settings::put('installation', ['installed_at' => now()->toISOString(), 'provider' => config('cms.data_provider'), 'schema_version' => 1, 'bootstrap_locked' => true]);
        });
        $this->info('Installation complete. Sign in and enroll MFA at /security. Configure business details, email and payment providers next.');

        return self::SUCCESS;
    }
}

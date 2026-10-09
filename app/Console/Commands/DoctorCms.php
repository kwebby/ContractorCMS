<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace App\Console\Commands;

use App\Contracts\RecordRepository;
use Illuminate\Console\Command;

class DoctorCms extends Command
{
    protected $signature = 'cms:doctor {--install : Include production installation requirements}';

    protected $description = 'Check runtime, storage, configuration and database capabilities';

    public function handle(RecordRepository $records): int
    {
        $checks = ['PHP >= 8.4.1' => version_compare(PHP_VERSION, '8.4.1', '>='), 'Application key configured' => filled(config('app.key')), 'Protected writable storage' => is_writable(storage_path('app/private')) && ! str_starts_with(realpath(storage_path()), realpath(public_path())), 'Writable framework cache' => is_writable(storage_path('framework'))];
        foreach (['openssl', 'pdo', 'mbstring', 'tokenizer', 'xml', 'ctype', 'json', 'fileinfo', 'curl', 'zip', 'gd', 'intl', 'sodium', 'bcmath'] as $extension) {
            $checks['Extension: '.$extension] = extension_loaded($extension);
        }
        $provider = config('cms.data_provider');
        $checks['Selected provider supported'] = in_array($provider, ['postgres', 'mysql', 'supabase'], true) || ($provider === 'firestore' && config('cms.firestore.experimental'));
        if ($provider !== 'firestore') {
            $checks['Provider and SQL connection agree'] = in_array(config('database.default'), $provider === 'mysql' ? ['mysql'] : ['pgsql']);
        }
        try {
            $records->all('settings');
            $checks['Database connection/schema'] = true;
        } catch (\Throwable) {
            $checks['Database connection/schema'] = false;
        }
        if ($this->option('install') && app()->environment('production')) {
            $checks['HTTPS application URL'] = str_starts_with(config('app.url'), 'https://');
            $checks['Debug disabled'] = ! config('app.debug');
            $checks['Privileged MFA required'] = (bool) config('cms.mfa_required');
            $checks['Compiled frontend assets'] = is_file(public_path('build/manifest.json'));
        }
        $this->table(['Capability', 'Result'], array_map(fn ($name, $ok) => [$name, $ok ? 'PASS' : 'FAIL'], array_keys($checks), $checks));
        $this->line('Web root must be public/. Configure CLI cron every minute and verify outgoing HTTPS/SMTP with your provider.');

        return in_array(false, $checks, true) ? self::FAILURE : self::SUCCESS;
    }
}

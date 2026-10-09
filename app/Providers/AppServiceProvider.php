<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace App\Providers;

use App\Contracts\RecordRepository;
use App\Identity\RepositoryUserProvider;
use App\Infrastructure\FirestoreRecordRepository;
use App\Infrastructure\SqlRecordRepository;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RecordRepository::class, fn ($app) => config('cms.data_provider') === 'firestore' ? $app->make(FirestoreRecordRepository::class) : $app->make(SqlRecordRepository::class));
    }

    public function boot(): void
    {
        Auth::provider('cms', fn ($app) => new RepositoryUserProvider($app->make(RecordRepository::class)));
        RateLimiter::for('public', fn ($request) => Limit::perMinute(15)->by($request->ip()));
        RateLimiter::for('login', fn ($request) => Limit::perMinute(6)->by(strtolower(is_string($email = $request->input('email')) ? $email : '').'|'.$request->ip()));
    }
}

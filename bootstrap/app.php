<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

use App\Http\Middleware\CmsSecurity;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [HandleInertiaRequests::class, CmsSecurity::class]);
        $middleware->validateCsrfTokens(except: ['api/v1/payments/webhooks/stripe', 'api/v1/payments/webhooks/paypal']);
        $middleware->redirectGuestsTo('/login');
        if (env('APP_ENV') === 'production') {
            $middleware->trustHosts(at: fn () => ['^'.preg_quote((string) parse_url(config('app.url'), PHP_URL_HOST), '/').'$'], subdomains: false);
        }
        if ($proxies = env('TRUSTED_PROXIES')) {
            $middleware->trustProxies(at: array_values(array_filter(array_map('trim', explode(',', $proxies)))));
        }
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();

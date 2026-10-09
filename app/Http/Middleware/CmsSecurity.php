<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace App\Http\Middleware;

use App\Support\Access;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

class CmsSecurity
{
    public function handle(Request $request, Closure $next): Response
    {
        Vite::useCspNonce();
        if ($request->user()) {
            if (! ($request->user()->active ?? true)) {
                auth()->logout();
                $request->session()->invalidate();

                return $request->is('api/*') ? response()->json(['message' => 'Account access has been disabled.'], 401) : redirect('/login');
            }
            $version = $request->user()->session_version ?? 0;
            if ($request->session()->has('user_session_version') && $request->session()->get('user_session_version') !== $version) {
                auth()->logout();
                $request->session()->invalidate();

                return $request->is('api/*') ? response()->json(['message' => 'Your session expired. Sign in again.'], 401) : redirect('/login');
            }
            $request->session()->put('user_session_version', $version);
        }
        if (config('cms.mfa_required') && Access::privileged($request->user()) && ! $request->is('logout', 'mfa', 'security', 'api/v1/security/*') && ! $request->session()->get('mfa_passed')) {
            if ($request->is('api/*')) {
                abort(403, 'Set up and confirm two-factor authentication before continuing.');
            }

            return redirect('/security');
        }
        $response = $next($request);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        if (! $response->headers->has('Referrer-Policy')) {
            $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        }
        if ($request->secure() && app()->environment('production')) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        if (! (app()->environment('local') && is_file(public_path('hot')))) {
            $nonce = Vite::cspNonce();
            $response->headers->set('Content-Security-Policy', "default-src 'self'; script-src 'self' 'nonce-{$nonce}'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self'; frame-src 'self' blob:; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'");
        }
        if ($request->is('app*', 'api/*', 'security', 'login', 'register')) {
            $response->headers->set('Cache-Control', 'private, no-store');
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }
}

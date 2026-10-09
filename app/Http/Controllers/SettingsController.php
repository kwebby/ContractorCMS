<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace App\Http\Controllers;

use App\Contracts\RecordRepository;
use App\Support\Access;
use App\Support\Outbox;
use App\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

class SettingsController extends Controller
{
    public function get(string $group): mixed
    {
        Access::authorize('settings.manage');
        abort_unless(in_array($group, ['business', 'mail', 'payments', 'ai', 'notifications']), 404);

        return response()->json(['data' => Settings::masked($group)]);
    }

    public function put(Request $request, string $group): mixed
    {
        Access::authorize('settings.manage');
        $rules = match ($group) {
            'business' => ['name' => 'required|string|max:160', 'legal_name' => 'nullable|string|max:160', 'email' => 'nullable|email', 'phone' => 'nullable|string|max:40', 'address' => 'nullable|string|max:300', 'city' => 'nullable|string|max:100', 'region' => 'nullable|string|max:100', 'postal_code' => 'nullable|string|max:20', 'country' => 'required|in:US,CA', 'currency' => 'required|in:USD,CAD', 'timezone' => 'required|timezone', 'trade' => 'nullable|string|max:100', 'service_areas' => 'sometimes|array|max:100', 'service_areas.*' => 'string|max:100', 'tax_id' => 'nullable|string|max:80', 'website' => 'nullable|url:https,http|max:300', 'logo_url' => 'nullable|string|max:300'],
            'mail' => ['host' => 'required|string|max:253', 'port' => 'required|integer|in:25,465,587,2525', 'encryption' => 'required|in:tls,ssl', 'username' => 'nullable|string|max:254', 'password' => 'nullable|string|max:2000', 'from_address' => 'required|email', 'from_name' => 'required|string|max:160', 'reply_to' => 'nullable|email'],
            'payments' => ['stripe_enabled' => 'sometimes|boolean', 'stripe_secret_key' => 'nullable|string|max:2000', 'stripe_webhook_secret' => 'nullable|string|max:2000', 'stripe_publishable_key' => 'nullable|string|max:300', 'paypal_enabled' => 'sometimes|boolean', 'paypal_client_id' => 'nullable|string|max:1000', 'paypal_client_secret' => 'nullable|string|max:2000', 'paypal_webhook_id' => 'nullable|string|max:200', 'paypal_mode' => 'required|in:sandbox,live'],
            'ai' => ['enabled' => 'required|boolean', 'api_key' => 'nullable|string|max:2000', 'model' => 'required|string|max:100', 'daily_request_limit' => 'required|integer|min:1|max:1000', 'daily_token_limit' => 'required|integer|min:1000|max:1000000'],
            'notifications' => ['email_enabled' => 'required|boolean', 'digest' => 'required|in:instant,daily'],
            default => abort(404),
        };
        if (in_array($group, ['mail', 'payments', 'ai'])) {
            abort_unless(time() - (int) session('auth.password_confirmed_at', 0) < 900, 423, 'Confirm your password to change credentials.');
        }
        $data = $request->validate($rules);
        $old = Settings::get($group);
        foreach ($data as $key => &$value) {
            if ($value === '••••••••') {
                $value = $old[$key] ?? null;
            }
        }
        Settings::put($group, array_replace($old, $data));
        Outbox::audit('settings.updated', ['group' => $group]);

        return response()->json(['data' => Settings::masked($group), 'message' => 'Settings saved.']);
    }

    public function confirmPassword(Request $request): mixed
    {
        $request->validate(['password' => 'required|string']);
        abort_unless(Hash::check($request->input('password'), $request->user()->getAuthPassword()), 422, 'Password does not match.');
        session(['auth.password_confirmed_at' => time()]);

        return response()->json(['message' => 'Password confirmed.']);
    }

    public function testMail(Request $request): mixed
    {
        Access::authorize('settings.manage');
        $data = $request->validate(['email' => 'required|email']);
        Outbox::push('email', ['to' => $data['email'], 'subject' => 'Email configuration test', 'text' => 'Your ContractorCMS email configuration is working.']);

        return response()->json(['message' => 'Test email queued. Check delivery status in system health.']);
    }

    public function health(RecordRepository $records): mixed
    {
        Access::authorize('settings.manage');
        $pending = $records->all('outbox', ['status' => 'pending']);
        $failed = $records->all('outbox', ['status' => 'failed']);
        $pendingDates = array_column([...$pending, ...$records->all('outbox', ['status' => 'processing'])], 'created_at');
        sort($pendingDates);
        $heartbeat = Settings::get('scheduler')['last_run_at'] ?? null;
        $extra = ['storage_free_mib' => (int) floor(disk_free_space(storage_path('app/private')) / 1048576), 'queue_oldest_at' => $pendingDates[0] ?? null, 'cron_healthy' => $heartbeat ? now()->diffInMinutes(Carbon::parse($heartbeat), true) < 3 : false];

        return response()->json(['data' => [...$extra, 'database' => config('cms.data_provider'), 'php' => PHP_VERSION, 'storage_writable' => is_writable(storage_path('app/private')), 'scheduler' => Settings::get('scheduler'), 'pending' => count($pending), 'failed' => array_map(fn ($r) => Arr::only($r, ['id', 'type', 'attempts', 'last_error', 'updated_at']), $failed), 'mail_configured' => (bool) (Settings::get('mail')['host'] ?? null), 'ai_configured' => (bool) (Settings::get('ai')['enabled'] ?? false)]]);
    }
}

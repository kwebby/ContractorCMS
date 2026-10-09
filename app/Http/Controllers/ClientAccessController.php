<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace App\Http\Controllers;

use App\Contracts\RecordRepository;
use App\Identity\CmsUser;
use App\Support\Access;
use App\Support\Outbox;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ClientAccessController extends Controller
{
    public function __construct(private RecordRepository $records) {}

    public function invite(Request $request, string $id): mixed
    {
        Access::authorize('crm.manage');
        abort_unless(time() - (int) session('auth.password_confirmed_at', 0) < 900, 423, 'Confirm your password to grant access to customer records.');
        $customer = $this->records->get('customers', $id);
        $data = $request->validate(['email' => 'required|email|max:254']);
        $email = strtolower($data['email']);
        $token = Str::random(64);
        $this->records->create('tokens', ['type' => 'client_invite', 'customer_id' => $id, 'email' => $email, 'name' => $customer['name'], 'invited_by' => $request->user()->id, 'expires_at' => now()->addDays(3)->toISOString(), 'used_at' => null], hash('sha256', $token));
        Outbox::push('email', ['to' => $email, 'template_key' => 'invitation', 'template_data' => ['recipient_name' => $customer['name'], 'role' => 'client', 'action_url' => rtrim(config('app.url'), '/').'/portal-invitations/'.$token]]);
        Outbox::audit('client.portal_invited', ['customer_id' => $id, 'email' => $email]);

        return response()->json(['message' => 'Portal invitation queued. The recipient must verify the link and sign in or create a password.'], 201);
    }

    public function invitation(string $token): mixed
    {
        $invite = $this->token($token);
        $existing = $this->records->all('users', ['email' => $invite['email']])[0] ?? null;

        return response()->view('portal-invitation', ['token' => $token, 'existing' => (bool) $existing, 'email' => $invite['email'], 'name' => $invite['name']])->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, nofollow')->header('Referrer-Policy', 'no-referrer');
    }

    public function accept(Request $request): mixed
    {
        $data = $request->validate(['token' => 'required|string', 'name' => 'required|string|max:120', 'password' => 'required|string|min:12', 'password_confirmation' => 'required|same:password']);
        $user = $this->records->transaction(function () use ($data) {
            $invite = $this->token($data['token']);
            $key = hash('sha256', $data['token']);
            $this->records->lock('token:'.$key);
            $invite = $this->token($data['token']);
            $this->records->lock('email:'.$invite['email']);
            $existing = $this->records->all('users', ['email' => $invite['email']])[0] ?? null;
            if ($existing) {
                abort_unless($existing['role'] === 'client' && ($existing['active'] ?? true), 422, 'Ask the office to send an invitation to a client account.');
                abort_unless(Hash::check($data['password'], $existing['password']), 422, 'Use your existing account password.');
                $user = $this->records->update('users', $existing['id'], ['customer_id' => $invite['customer_id'], 'email_verified_at' => now()->toISOString(), 'session_version' => ($existing['session_version'] ?? 0) + 1]);
            } else {
                $user = $this->records->create('users', ['name' => $data['name'], 'email' => $invite['email'], 'password' => Hash::make($data['password']), 'role' => 'client', 'customer_id' => $invite['customer_id'], 'email_verified_at' => now()->toISOString(), 'active' => true, 'session_version' => 1]);
            }
            $this->records->update('tokens', $key, ['used_at' => now()->toISOString()]);
            Outbox::audit('client.portal_linked', ['user_id' => $user['id'], 'customer_id' => $invite['customer_id'], 'invited_by' => $invite['invited_by']]);

            return $user;
        });
        $request->session()->regenerate();
        $request->session()->forget(['user_session_version', 'mfa_passed', 'mfa_pending', 'mfa_pending_at', 'mfa_pending_session_version']);
        if ($user['mfa_enabled'] ?? false) {
            session(['mfa_pending' => $user['id'], 'mfa_pending_at' => time(), 'mfa_pending_session_version' => $user['session_version'] ?? 0]);

            return redirect('/mfa')->with('success', 'Your customer portal is linked. Enter your authentication code to continue.');
        }
        Auth::login(new CmsUser($user));

        return redirect('/app')->with('success', 'Your customer portal is ready.');
    }

    public function participants(Request $request): mixed
    {
        Access::authorize('chat.use');
        $user = $request->user();
        $customers = [];
        if ($user->role === 'technician') {
            foreach ($this->records->all('jobs') as $job) {
                if (in_array($user->id, $job['assigned_to'] ?? [], true)) {
                    $customers[] = $job['customer_id'];
                }
            }
        }
        $users = array_filter($this->records->all('users'), function ($candidate) use ($user, $customers) {
            if (! ($candidate['active'] ?? true)) {
                return false;
            }
            if ($user->role === 'client') {
                return in_array($candidate['role'], ['owner', 'office']);
            }
            if ($candidate['role'] !== 'client') {
                return true;
            }
            if (empty($candidate['email_verified_at'])) {
                return false;
            }

            return Access::can($user, 'crm.manage') || Access::can($user, 'operations.manage') || in_array($candidate['customer_id'] ?? null, $customers, true);
        });

        return response()->json(['data' => array_values(array_map(fn ($row) => Arr::only($row, ['id', 'name', 'role', 'customer_id']), $users))]);
    }

    private function token(string $token): array
    {
        $row = $this->records->get('tokens', hash('sha256', $token));
        abort_unless(($row['type'] ?? '') === 'client_invite' && empty($row['used_at']) && now()->lt($row['expires_at']), 422, 'This invitation is expired or already used.');

        return $row;
    }
}

<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace App\Http\Controllers;

use App\Contracts\RecordRepository;
use App\Identity\CmsUser;
use App\Support\Outbox;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use PragmaRX\Google2FA\Google2FA;

class AuthController extends Controller
{
    public function __construct(private RecordRepository $records) {}

    public function loginPage(): mixed
    {
        return Inertia::render('Login');
    }

    public function registerPage(): mixed
    {
        return Inertia::render('Register');
    }

    public function login(Request $request): mixed
    {
        $data = $request->validate(['email' => 'required|email', 'password' => 'required|string']);
        if (! Auth::attempt($data)) {
            throw ValidationException::withMessages(['email' => 'Email or password is incorrect.']);
        }
        $request->session()->regenerate();
        session(['auth.password_confirmed_at' => time()]);
        $request->session()->forget(['mfa_passed', 'user_session_version', 'pending_mfa_secret', 'pending_mfa_user_id', 'pending_mfa_started_at', 'mfa_pending', 'mfa_pending_at', 'mfa_pending_session_version']);
        if ($request->user()->mfa_enabled) {
            session(['mfa_pending' => auth()->id(), 'mfa_pending_at' => time(), 'mfa_pending_session_version' => $request->user()->session_version ?? 0]);
            Auth::logout();

            return redirect('/mfa');
        }

        return redirect('/app');
    }

    public function register(Request $request): mixed
    {
        $data = $request->validate(['name' => 'required|string|max:120', 'email' => 'required|email|max:254', 'password' => ['required', 'confirmed', Password::min(12)], 'role' => 'prohibited', 'customer_id' => 'prohibited']);
        $email = strtolower(trim($data['email']));
        $user = $this->records->transaction(function () use ($data, $email): array {
            $this->records->lock('email:'.$email);
            if ($this->records->all('users', ['email' => $email])) {
                throw ValidationException::withMessages(['email' => 'An account already exists for this email.']);
            }
            $customer = $this->records->create('customers', ['name' => $data['name'], 'email' => $email, 'status' => 'new', 'source' => 'client_signup']);

            return $this->records->create('users', ['name' => $data['name'], 'email' => $email, 'password' => Hash::make($data['password']), 'role' => 'client', 'customer_id' => $customer['id'], 'email_verified_at' => null, 'active' => true]);
        });
        $token = $this->token('verify', $user['id'], 1440);
        Outbox::push('email', ['to' => $email, 'template_key' => 'verification', 'template_data' => ['recipient_name' => $user['name'], 'action_url' => rtrim(config('app.url'), '/').'/verify-email/'.$token]]);
        Auth::login(new CmsUser($user));
        $request->session()->regenerate();
        $request->session()->forget(['mfa_passed', 'mfa_pending', 'pending_mfa_secret', 'pending_mfa_user_id', 'pending_mfa_started_at', 'auth.password_confirmed_at', 'user_session_version']);

        return redirect('/app')->with('success', 'Check your email to verify your account.');
    }

    public function logout(Request $request): mixed
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }

    public function verify(string $token): mixed
    {
        $this->records->transaction(function () use ($token): void {
            $record = $this->consume($token, 'verify');
            $this->records->update('users', $record['user_id'], ['email_verified_at' => now()->toISOString()]);
        });

        return redirect('/app')->with('success', 'Email verified.');
    }

    public function forgotPage(): mixed
    {
        return Inertia::render('ForgotPassword');
    }

    public function forgot(Request $request): mixed
    {
        $request->validate(['email' => 'required|email']);
        $user = $this->records->all('users', ['email' => strtolower($request->input('email'))])[0] ?? null;
        if ($user) {
            $token = $this->token('reset', $user['id'], 30);
            Outbox::push('email', ['to' => $user['email'], 'template_key' => 'password_reset', 'template_data' => ['recipient_name' => $user['name'], 'action_url' => rtrim(config('app.url'), '/').'/reset-password/'.$token]]);
        }

        return back()->with('success', 'If the account exists, a reset link has been queued.');
    }

    public function resetPage(string $token): mixed
    {
        return Inertia::render('ResetPassword', ['token' => $token]);
    }

    public function reset(Request $request): mixed
    {
        $data = $request->validate(['token' => 'required|string', 'password' => ['required', 'confirmed', Password::min(12)]]);
        $this->records->transaction(function () use ($data): void {
            $token = $this->consume($data['token'], 'reset');
            $this->records->lock('user:'.$token['user_id']);
            $this->records->update('users', $token['user_id'], ['password' => Hash::make($data['password']), 'session_version' => ($this->records->get('users', $token['user_id'])['session_version'] ?? 0) + 1]);
            foreach ($this->records->all('tokens', ['user_id' => $token['user_id']]) as $other) {
                if ($other['type'] === 'reset' && empty($other['used_at'])) {
                    $this->records->update('tokens', $other['id'], ['used_at' => now()->toISOString()]);
                }
            }
        });

        return redirect('/login')->with('success', 'Password changed. Please sign in.');
    }

    public function security(): mixed
    {
        return view('auth-form', ['mode' => 'security', 'title' => 'Two-factor authentication', 'enabled' => auth()->user()->mfa_enabled ?? false]);
    }

    public function mfaPage(): mixed
    {
        abort_unless(session('mfa_pending'), 403);

        return Inertia::render('Mfa');
    }

    public function setupMfa(Request $request): mixed
    {
        $request->validate(['password' => 'required|string']);
        abort_unless(Hash::check($request->input('password'), $request->user()->getAuthPassword()), 422, 'Password does not match.');
        $secret = (new Google2FA)->generateSecretKey();
        session(['pending_mfa_secret' => $secret, 'pending_mfa_user_id' => $request->user()->id, 'pending_mfa_started_at' => time()]);

        return response()->json(['secret' => $secret, 'uri' => (new Google2FA)->getQRCodeUrl(config('app.name'), $request->user()->email, $secret)]);
    }

    public function confirmMfa(Request $request): mixed
    {
        $request->validate(['code' => 'required|digits:6']);
        $secret = session('pending_mfa_secret');
        abort_unless($secret && session('pending_mfa_user_id') === $request->user()->id && time() - (int) session('pending_mfa_started_at', 0) < 600 && (new Google2FA)->verifyKey($secret, $request->input('code'), 1), 422, 'Authentication code is invalid.');
        $this->records->update('users', auth()->id(), ['mfa_secret' => Crypt::encryptString($secret), 'mfa_enabled' => true, 'mfa_last_code' => hash('sha256', $request->input('code')), 'mfa_last_at' => now()->timestamp]);
        session()->forget(['pending_mfa_secret', 'pending_mfa_user_id', 'pending_mfa_started_at']);
        session(['mfa_passed' => true]);
        Outbox::audit('mfa.enabled');

        return response()->json(['message' => 'Two-factor authentication enabled.']);
    }

    public function mfa(Request $request): mixed
    {
        $request->validate(['code' => 'required|digits:6']);
        abort_unless(session('mfa_pending') && time() - (int) session('mfa_pending_at', 0) < 600, 422, 'The authentication challenge expired. Sign in again.');
        $user = $this->records->transaction(function () use ($request): array {
            $id = (string) session('mfa_pending');
            $this->records->lock('mfa:'.$id);
            $user = $this->records->get('users', $id);
            abort_unless(($user['active'] ?? true) && ($user['session_version'] ?? 0) === session('mfa_pending_session_version', 0), 403);
            $replay = ($user['mfa_last_code'] ?? '') === hash('sha256', $request->input('code')) && now()->timestamp - ($user['mfa_last_at'] ?? 0) < 90;
            if ($replay || ! (new Google2FA)->verifyKey(Crypt::decryptString($user['mfa_secret']), $request->input('code'), 1)) {
                throw ValidationException::withMessages(['code' => 'Authentication code is invalid or already used.']);
            }

            return $this->records->update('users', $id, ['mfa_last_code' => hash('sha256', $request->input('code')), 'mfa_last_at' => now()->timestamp]);
        });
        Auth::login(new CmsUser($user));
        $request->session()->regenerate();
        session()->forget(['mfa_pending', 'mfa_pending_at', 'mfa_pending_session_version', 'user_session_version']);
        session(['mfa_passed' => true]);

        return redirect('/app');
    }

    public function invitationPage(string $token): mixed
    {
        return view('auth-form', ['mode' => 'invite', 'title' => 'Join your team', 'token' => $token]);
    }

    public function acceptInvitation(Request $request): mixed
    {
        $data = $request->validate(['token' => 'required|string', 'name' => 'required|string|max:120', 'password' => ['required', 'confirmed', Password::min(12)]]);
        $user = $this->records->transaction(function () use ($data): array {
            $invitation = $this->consume($data['token'], 'invite');
            $this->records->lock('email:'.$invitation['email']);
            abort_if((bool) $this->records->all('users', ['email' => $invitation['email']]), 409, 'An account already exists. Ask the owner to update access.');

            return $this->records->create('users', ['name' => $data['name'], 'email' => $invitation['email'], 'role' => $invitation['role'], 'password' => Hash::make($data['password']), 'email_verified_at' => now()->toISOString(), 'active' => true]);
        });
        Auth::login(new CmsUser($user));
        $request->session()->regenerate();
        $request->session()->forget(['mfa_passed', 'mfa_pending', 'pending_mfa_secret', 'pending_mfa_user_id', 'pending_mfa_started_at', 'auth.password_confirmed_at', 'user_session_version']);

        return redirect('/app');
    }

    private function token(string $type, string $userId, int $minutes): string
    {
        $token = Str::random(64);
        $this->records->create('tokens', ['type' => $type, 'user_id' => $userId, 'expires_at' => now()->addMinutes($minutes)->toISOString(), 'used_at' => null], hash('sha256', $token));

        return $token;
    }

    private function consume(string $token, string $type): array
    {
        $id = hash('sha256', $token);
        $this->records->lock('token:'.$id);
        $record = $this->records->get('tokens', $id);
        abort_unless($record['type'] === $type && ! $record['used_at'] && now()->lt($record['expires_at']), 422, 'This link is invalid or expired.');
        $this->records->update('tokens', $id, ['used_at' => now()->toISOString()]);

        return $record;
    }
}

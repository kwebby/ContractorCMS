<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace Tests\Feature;

use App\Contracts\RecordRepository;
use App\Identity\CmsUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class MfaSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_privileged_login_requires_mfa_and_rejects_replayed_code(): void
    {
        config(['cms.data_provider' => 'postgres', 'cms.mfa_required' => true]);
        $totp = new Google2FA;
        $secret = $totp->generateSecretKey();
        app(RecordRepository::class)->create('users', ['name' => 'Owner', 'email' => 'owner@example.test', 'password' => Hash::make('long-owner-password'), 'role' => 'owner', 'active' => true, 'mfa_enabled' => true, 'mfa_secret' => Crypt::encryptString($secret), 'email_verified_at' => now()->toISOString()]);
        $login = ['email' => 'owner@example.test', 'password' => 'long-owner-password'];
        $this->post('/login', $login)->assertRedirect('/mfa');
        $this->assertGuest();
        $code = $totp->getCurrentOtp($secret);
        $this->post('/mfa', ['code' => $code])->assertRedirect('/app');
        $this->assertAuthenticated();
        $this->getJson('/api/v1/settings/business')->assertOk();
        $this->post('/logout')->assertRedirect('/login');
        $this->post('/login', $login)->assertRedirect('/mfa');
        $this->postJson('/mfa', ['code' => $code])->assertUnprocessable();
        $this->assertGuest();
    }

    public function test_financial_staff_without_mfa_are_routed_to_enrollment(): void
    {
        config(['cms.data_provider' => 'postgres', 'cms.mfa_required' => true]);
        $this->actingAs(new CmsUser(['id' => 'office', 'name' => 'Office', 'role' => 'office']));
        $this->get('/app')->assertRedirect('/security');
        $this->getJson('/api/v1/invoices')->assertForbidden();
        $this->get('/security')->assertOk();
    }
}

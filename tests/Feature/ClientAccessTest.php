<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace Tests\Feature;

use App\Contracts\RecordRepository;
use App\Identity\CmsUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ClientAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_linking_requires_authorized_invitation_and_password_and_cannot_replay(): void
    {
        config(['cms.data_provider' => 'postgres', 'cms.mfa_required' => false]);
        $r = app(RecordRepository::class);
        $customer = $r->create('customers', ['name' => 'Avery']);
        $owner = new CmsUser($r->create('users', ['name' => 'Owner', 'role' => 'owner', 'email' => 'owner@example.test']));
        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()])->postJson('/api/v1/customers/'.$customer['id'].'/portal-invitation', ['email' => 'client@example.test'])->assertCreated();
        $payload = $r->all('outbox')[0]['payload'];
        preg_match('~/portal-invitations/([a-zA-Z0-9]+)~', $payload['body'], $match);
        $token = $match[1];
        auth()->logout();
        $this->get('/portal-invitations/'.$token)->assertOk();
        $form = ['token' => $token, 'name' => 'Avery', 'password' => 'long-password-123', 'password_confirmation' => 'long-password-123'];
        $this->post('/portal-invitations/accept', $form)->assertRedirect('/app');
        $client = $r->all('users', ['email' => 'client@example.test'])[0];
        $this->assertSame($customer['id'], $client['customer_id']);
        $this->assertNotEmpty($client['email_verified_at']);
        $this->postJson('/portal-invitations/accept', $form)->assertUnprocessable();
    }

    public function test_existing_account_cannot_be_claimed_without_its_password(): void
    {
        config(['cms.data_provider' => 'postgres', 'cms.mfa_required' => false]);
        $r = app(RecordRepository::class);
        $r->create('users', ['name' => 'Avery', 'email' => 'client@example.test', 'role' => 'client', 'password' => Hash::make('correct-password-123'), 'customer_id' => 'old']);
        $r->create('tokens', ['type' => 'client_invite', 'customer_id' => 'new', 'email' => 'client@example.test', 'name' => 'Avery', 'expires_at' => now()->addHour()->toISOString(), 'used_at' => null, 'invited_by' => 'office'], hash('sha256', 'invitationtoken'));
        $this->postJson('/portal-invitations/accept', ['token' => 'invitationtoken', 'name' => 'Avery', 'password' => 'incorrect-password', 'password_confirmation' => 'incorrect-password'])->assertUnprocessable();
        $this->assertSame('old', $r->all('users')[0]['customer_id']);
    }
}

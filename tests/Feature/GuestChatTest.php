<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace Tests\Feature;

use App\Contracts\RecordRepository;
use App\Identity\CmsUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class GuestChatTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_chat_is_session_scoped_and_reconnects_without_duplicate_messages(): void
    {
        config(['cms.data_provider' => 'postgres', 'cms.mfa_required' => false]);
        $records = app(RecordRepository::class);
        $owner = $records->create('users', ['name' => 'Office', 'email' => 'office@example.test', 'role' => 'owner', 'active' => true]);
        $input = ['name' => 'Avery', 'email' => 'guest@example.test', 'body' => 'Please arrange a visit.', 'idempotency_key' => (string) Str::uuid()];
        $this->withSession(['guest_chat_identity' => 'guest-one']);
        $chat = $this->postJson('/api/v1/public/chat', $input)->assertCreated()->json('data');
        $this->postJson('/api/v1/public/chat', $input)->assertCreated();
        $this->assertCount(1, $records->all('leads'));
        $this->assertCount(1, $records->all('messages'));
        $this->getJson('/api/v1/public/chat/'.$chat['id'].'/messages')->assertOk()->assertJsonCount(1, 'data');
        $this->withSession(['guest_chat_identity' => 'guest-two'])->getJson('/api/v1/public/chat/'.$chat['id'].'/messages')->assertForbidden();
        $this->actingAs(new CmsUser($owner))->postJson('/api/v1/conversations/'.$chat['id'].'/messages', ['body' => 'We will contact you shortly.', 'idempotency_key' => 'staff-response-123'])->assertCreated();
        $this->withSession(['guest_chat_identity' => 'guest-one'])->getJson('/api/v1/public/chat/'.$chat['id'].'/messages?after=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.body', 'We will contact you shortly.')->assertJsonPath('next_cursor', 2);
    }
}

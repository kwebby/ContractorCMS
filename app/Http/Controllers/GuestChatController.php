<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace App\Http\Controllers;

use App\Contracts\RecordRepository;
use App\Support\Outbox;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class GuestChatController extends Controller
{
    public function __construct(private RecordRepository $records) {}

    public function start(Request $request): mixed
    {
        $data = $request->validate(['name' => 'required|string|max:120', 'email' => 'required|email|max:254', 'body' => 'required|string|max:3000', 'idempotency_key' => 'required|uuid', 'website' => 'nullable|max:0']);
        $sessionKey = session('guest_chat_identity');
        if (! $sessionKey) {
            $sessionKey = (string) Str::uuid();
            session(['guest_chat_identity' => $sessionKey]);
        }
        $id = hash('sha256', $sessionKey.':'.$data['idempotency_key']);
        $conversation = $this->records->transaction(function () use ($data, $sessionKey, $id) {
            $this->records->lock('guestchat:'.$id);
            if ($existing = $this->records->find('conversations', $id)) {
                return $existing;
            }
            $staff = array_column(array_filter($this->records->all('users'), fn ($user) => in_array($user['role'], ['owner', 'office']) && ($user['active'] ?? true)), 'id');
            $lead = $this->records->create('leads', ['name' => $data['name'], 'email' => strtolower($data['email']), 'notes' => $data['body'], 'source' => 'website_chat', 'stage' => 'new', 'priority' => 'normal', 'marketing_consent' => false]);
            $conversation = $this->records->create('conversations', ['title' => 'Website inquiry · '.$data['name'], 'type' => 'guest', 'participants' => $staff, 'guest_identity_hash' => hash('sha256', $sessionKey), 'guest_name' => $data['name'], 'lead_id' => $lead['id'], 'expires_at' => now()->addDays(7)->toISOString(), 'status' => 'open'], $id);
            $this->persist($conversation, $data['body'], $data['idempotency_key'], $sessionKey);

            return $conversation;
        });

        return response()->json(['data' => ['id' => $conversation['id'], 'title' => $conversation['title']]], 201);
    }

    public function messages(Request $request, string $id): mixed
    {
        $this->authorized($id);
        $request->validate(['after' => 'sometimes|integer|min:0']);
        $messages = array_values(array_filter($this->records->all('messages', ['conversation_id' => $id]), fn ($row) => $row['sequence'] > (int) $request->input('after', 0)));
        usort($messages, fn ($a, $b) => $a['sequence'] <=> $b['sequence']);
        $messages = array_slice($messages, 0, 100);

        return response()->json(['data' => array_map(fn ($row) => Arr::only($row, ['id', 'sender_name', 'body', 'sequence', 'created_at']), $messages), 'next_cursor' => count($messages) ? end($messages)['sequence'] : (int) $request->input('after', 0)]);
    }

    public function send(Request $request, string $id): mixed
    {
        $conversation = $this->authorized($id);
        $data = $request->validate(['body' => 'required|string|max:3000', 'idempotency_key' => 'required|uuid']);
        $message = $this->records->transaction(fn () => $this->persist($conversation, $data['body'], $data['idempotency_key'], session('guest_chat_identity')));

        return response()->json(['data' => Arr::only($message, ['id', 'sender_name', 'body', 'sequence', 'created_at'])], 201);
    }

    private function authorized(string $id): array
    {
        $row = $this->records->get('conversations', $id);
        abort_unless(($row['type'] ?? '') === 'guest' && session('guest_chat_identity') && hash_equals($row['guest_identity_hash'], hash('sha256', session('guest_chat_identity'))) && now()->lt($row['expires_at']), 403);

        return $row;
    }

    private function persist(array $conversation, string $body, string $key, string $identity): array
    {
        $id = hash('sha256', $conversation['id'].':guest:'.$key);
        $this->records->lock('guest-message:'.$id);
        if ($existing = $this->records->find('messages', $id)) {
            return $existing;
        }
        $record = $this->records->create('messages', ['conversation_id' => $conversation['id'], 'sender_id' => 'guest:'.hash('sha256', $identity), 'sender_name' => $conversation['guest_name'], 'body' => $body, 'file_ids' => [], 'sequence' => $this->records->sequence('conversation:'.$conversation['id'])], $id);
        $this->records->update('conversations', $conversation['id'], ['last_message' => Str::limit($body, 120), 'last_message_at' => now()->toISOString()]);
        foreach ($conversation['participants'] as $uid) {
            Outbox::notify($uid, 'New website inquiry', 'messages', '/app/messages', 'high');
        }

        return $record;
    }
}

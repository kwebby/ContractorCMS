<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace App\Http\Controllers;

use App\Contracts\RecordRepository;
use App\Support\Access;
use App\Support\Outbox;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class CommunicationsController extends Controller
{
    public function __construct(private RecordRepository $records) {}

    public function conversations(Request $request): mixed
    {
        $data = array_values(array_filter($this->records->all('conversations'), fn (array $r): bool => $this->member($request, $r)));

        return response()->json(['data' => $data]);
    }

    public function create(Request $request): mixed
    {
        Access::authorize('chat.use');
        $data = $request->validate(['title' => 'required|string|max:180', 'type' => 'required|in:client,internal,job', 'participants' => 'sometimes|array|max:30', 'participants.*' => 'string|max:128', 'customer_id' => 'nullable|string|max:128', 'job_id' => 'nullable|string|max:128']);
        $user = $request->user();
        if ($user->role !== 'client' && $data['type'] !== 'internal' && empty($data['job_id'])) {
            abort_unless(Access::can($user, 'crm.manage') || Access::can($user, 'operations.manage') || Access::can($user, 'finance.manage'), 403, 'Select an assigned job before contacting its client.');
        }
        $ids = array_unique(array_merge($data['participants'] ?? [], [$user->id]));
        if ($user->role === 'client') {
            abort_unless($user->email_verified_at, 403, 'Verify your email first.');
            $data['type'] = 'client';
            $data['customer_id'] = $user->customer_id;
            $staff = array_values(array_filter($this->records->all('users'), fn ($u) => in_array($u['role'], ['owner', 'office']) && ($u['active'] ?? true)));
            $ids = array_merge([$user->id], array_column($staff, 'id'));
        }
        if (! empty($data['job_id'])) {
            $job = $this->records->get('jobs', $data['job_id']);
            Access::authorizeRecord($user, $job, 'operations.manage');
            $data['customer_id'] = $job['customer_id'];
        }
        foreach ($ids as $id) {
            $participant = $this->records->get('users', $id);
            if ($data['type'] === 'internal') {
                abort_if($participant['role'] === 'client', 422, 'Internal conversations cannot include clients.');
            }
            if ($participant['role'] === 'client') {
                abort_unless(! empty($data['customer_id']) && $participant['customer_id'] === $data['customer_id'], 422, 'Client does not belong to this customer account.');
            }
        }
        $record = $this->records->create('conversations', array_merge($data, ['participants' => array_values(array_unique($ids)), 'status' => 'open', 'created_by' => $user->id]));

        return response()->json(['data' => $record], 201);
    }

    public function messages(Request $request, string $id): mixed
    {
        $conversation = $this->records->get('conversations', $id);
        abort_unless($this->member($request, $conversation), 403);
        $input = $request->validate(['after' => 'sometimes|integer|min:0', 'limit' => 'sometimes|integer|min:1|max:100']);
        $after = (int) ($input['after'] ?? 0);
        $messages = array_values(array_filter($this->records->all('messages', ['conversation_id' => $id]), fn ($r) => $r['sequence'] > $after));
        usort($messages, fn ($a, $b) => $a['sequence'] <=> $b['sequence']);
        $hasMore = count($messages) > ($input['limit'] ?? 100);
        $messages = array_slice($messages, 0, $input['limit'] ?? 100);

        return response()->json(['data' => $messages, 'next_cursor' => count($messages) ? end($messages)['sequence'] : $after, 'has_more' => $hasMore]);
    }

    public function send(Request $request, string $id): mixed
    {
        $conversation = $this->records->get('conversations', $id);
        abort_unless($this->member($request, $conversation), 403);
        $data = $request->validate(['body' => 'required|string|max:10000', 'idempotency_key' => 'required|string|min:8|max:128', 'file_ids' => 'sometimes|array|max:5', 'file_ids.*' => 'string']);
        $message = $this->records->transaction(function () use ($data, $id, $request, $conversation): array {
            $key = hash('sha256', $id.':'.$request->user()->id.':'.$data['idempotency_key']);
            $this->records->lock('message:'.$key);
            if ($record = $this->records->find('messages', $key)) {
                abort_unless($record['body'] === $data['body'] && ($record['file_ids'] ?? []) === ($data['file_ids'] ?? []), 409, 'This idempotency key belongs to another message.');

                return $record;
            }
            foreach ($data['file_ids'] ?? [] as $fileId) {
                $file = $this->records->get('files', $fileId);
                abort_unless($file['owner_id'] === $request->user()->id && $file['status'] === 'ready', 403);
                abort_unless(empty($file['resource_id']) || (($file['resource_type'] ?? '') === 'conversations' && $file['resource_id'] === $id), 403, 'Upload a separate attachment instead of moving a shared file.');
                $this->records->update('files', $fileId, ['resource_type' => 'conversations', 'resource_id' => $id]);
            }
            $record = $this->records->create('messages', ['conversation_id' => $id, 'sender_id' => $request->user()->id, 'sender_name' => $request->user()->name, 'body' => $data['body'], 'file_ids' => $data['file_ids'] ?? [], 'sequence' => $this->records->sequence('conversation:'.$id)], $key);
            $this->records->update('conversations', $id, ['last_message' => Str::limit($data['body'], 120), 'last_message_at' => now()->toISOString()]);
            foreach ($conversation['participants'] ?? [] as $uid) {
                if ($uid !== $request->user()->id) {
                    Outbox::notify($uid, 'New message: '.$conversation['title'], 'messages', '/app/messages');
                }
            }

            return $record;
        });

        return response()->json(['data' => $message], 201);
    }

    public function notifications(Request $request): mixed
    {
        return response()->json(['data' => array_reverse($this->records->all('notifications', ['user_id' => $request->user()->id]))]);
    }

    public function readNotification(Request $request, string $id): mixed
    {
        $record = $this->records->get('notifications', $id);
        abort_unless($record['user_id'] === $request->user()->id, 403);

        return response()->json(['data' => $this->records->update('notifications', $id, ['read_at' => $request->boolean('read', true) ? now()->toISOString() : null])]);
    }

    public function team(Request $request): mixed
    {
        if ($request->user()->role === 'client') {
            abort_unless($request->user()->email_verified_at, 403);
            $data = $this->records->all('users', ['role' => 'owner']);
        } else {
            $data = array_filter($this->records->all('users'), fn ($u) => $u['role'] !== 'client');
        }
        $fields = match (true) {
            $request->user()->role === 'client' => ['id', 'name', 'role'],
            Access::can($request->user(), 'team.manage') => ['id', 'name', 'email', 'role', 'active', 'email_verified_at', 'created_at'],
            default => ['id', 'name', 'role', 'active'],
        };

        return response()->json(['data' => array_values(array_map(fn ($u) => Arr::only($u, $fields), $data)), 'roles' => array_keys(Access::ROLES)]);
    }

    public function invite(Request $request): mixed
    {
        Access::authorize('team.manage');
        abort_unless(time() - (int) session('auth.password_confirmed_at', 0) < 900, 423, 'Please confirm your password.');
        $data = $request->validate(['name' => 'required|string|max:120', 'email' => 'required|email|max:254', 'role' => 'required|in:owner,office,dispatcher,estimator,technician,finance,payroll,marketing']);
        Access::authorizeRoleGrant($request->user(), $data['role']);
        $data['email'] = strtolower($data['email']);
        $token = Str::random(64);
        $record = $this->records->create('tokens', $data + ['type' => 'invite', 'expires_at' => now()->addDays(3)->toISOString(), 'used_at' => null, 'invited_by' => $request->user()->id], hash('sha256', $token));
        Outbox::push('email', ['to' => $data['email'], 'template_key' => 'invitation', 'template_data' => ['recipient_name' => $data['name'], 'role' => $data['role'], 'action_url' => rtrim(config('app.url'), '/').'/invitations/'.$token]], 'invite:'.$record['id']);
        Outbox::audit('team.invited', Arr::only($data, ['email', 'role']));

        return response()->json(['message' => 'Invitation queued. It expires in three days.'], 201);
    }

    public function updateMember(Request $request, string $id): mixed
    {
        Access::authorize('team.manage');
        abort_unless(time() - (int) session('auth.password_confirmed_at', 0) < 900, 423, 'Please confirm your password.');
        $data = $request->validate(['role' => 'sometimes|in:owner,office,dispatcher,estimator,technician,finance,payroll,marketing', 'active' => 'sometimes|boolean', 'custom_permissions' => 'sometimes|array', 'custom_permissions.*' => 'string|max:80']);
        abort_if($id === $request->user()->id, 422, 'You cannot change your own access here.');
        $record = $this->records->get('users', $id);
        if ($record['role'] === 'client') {
            abort_unless(array_keys($data) === ['active'], 422, 'Client accounts can only be activated or deactivated here.');
        } else {
            Access::authorizeRoleGrant($request->user(), $record['role'], $record['custom_permissions'] ?? []);
            Access::authorizeRoleGrant($request->user(), $data['role'] ?? $record['role'], $data['custom_permissions'] ?? []);
        }
        $record = $this->records->transaction(function () use ($id, $data) {
            $this->records->lock('user:'.$id);
            $current = $this->records->get('users', $id);

            return $this->records->update('users', $id, $data + ['session_version' => ($current['session_version'] ?? 0) + 1]);
        });
        Outbox::audit('team.access_changed', ['user_id' => $id]);

        return response()->json(['data' => Arr::only($record, ['id', 'name', 'email', 'role', 'active'])]);
    }

    private function member(Request $request, array $conversation): bool
    {
        $user = $request->user();
        if (! $user || ! Access::can($user, 'chat.use') || ($user->role === 'client' && (! $user->email_verified_at || ! $user->customer_id))) {
            return false;
        }

        return $user && (in_array($user->id, $conversation['participants'] ?? [], true) || ($user->role === 'owner' && ($conversation['type'] ?? '') === 'guest'));
    }
}

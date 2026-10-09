<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace App\Operations;

use App\Contracts\RecordRepository;
use App\Finance\Money;
use App\Support\Outbox;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class OperationsWorkflow
{
    public function __construct(private RecordRepository $records) {}

    public function requestVisit(array $input): array
    {
        $job = $this->records->get('jobs', $input['job_id']);
        $this->ensure(! in_array($job['status'] ?? '', ['completed', 'cancelled'], true), 'job_id', 'Reopen this job before requesting another visit.');
        $start = Carbon::parse($input['start_at']);
        $this->ensure($start->isFuture(), 'start_at', 'Choose a future appointment time.');

        return $this->records->create('visits', array_merge($input, ['customer_id' => $job['customer_id'], 'job_title' => $job['title'], 'status' => 'requested', 'start_at' => $start->toISOString(), 'requested_by' => auth()->id()]));
    }

    public function confirmVisit(string $id, int $version, array $input): array
    {
        return $this->records->transaction(function () use ($id, $version, $input) {
            $this->records->lock('schedule');
            $visit = $this->records->get('visits', $id);
            if ($visit['status'] === 'confirmed' && ($visit['confirmed_version'] ?? null) === $version) {
                return $visit;
            }
            $this->ensure($visit['status'] === 'requested' && $visit['version'] === $version, 'version', 'This appointment changed. Review the current request.');
            $visit = array_merge($visit, $input);
            $job = $this->records->get('jobs', $visit['job_id']);
            $this->ensure(! in_array($job['status'] ?? '', ['completed', 'cancelled'], true), 'job_id', 'This job is no longer open for scheduling.');
            $this->validateStaff($visit['assigned_to'] ?? []);
            $start = Carbon::parse($visit['start_at']);
            $this->ensure($start->isFuture(), 'start_at', 'Choose a future appointment time.');
            $this->assertAvailable($start, (int) $visit['duration_minutes'], $visit['assigned_to'], $visit['job_id'], $id);
            $visit = $this->records->update('visits', $id, array_merge($input, ['status' => 'confirmed', 'start_at' => $start->toISOString(), 'confirmed_version' => $version, 'confirmed_by' => auth()->id(), 'confirmed_at' => now()->toISOString()]), $version);
            $this->records->update('jobs', $job['id'], ['assigned_to' => array_values(array_unique(array_merge($job['assigned_to'] ?? [], $visit['assigned_to'])))]);
            $this->notifyCustomer($job['customer_id'], 'Appointment confirmed: '.$job['title'], 'appointments', $visit);
            Outbox::audit('visit.confirmed', ['visit_id' => $id, 'job_id' => $job['id']]);

            return $visit;
        });
    }

    public function closeVisit(string $id, int $version, string $state, string $reason): array
    {
        return $this->records->transaction(function () use ($id, $version, $state, $reason) {
            $this->records->lock('schedule');
            $visit = $this->records->get('visits', $id);
            if ($visit['status'] === $state && ($visit['closed_version'] ?? null) === $version) {
                return $visit;
            }
            $this->ensure($visit['version'] === $version, 'version', 'This appointment changed. Reload before continuing.');
            $allowed = $state === 'completed' ? ['confirmed'] : ['requested', 'confirmed'];
            $this->ensure(in_array($visit['status'], $allowed, true), 'status', 'This visit cannot move to the requested state.');
            $visit = $this->records->update('visits', $id, ['status' => $state, 'closed_version' => $version, 'closed_by' => auth()->id(), 'closed_at' => now()->toISOString(), 'reason' => $reason], $version);
            Outbox::audit('visit.'.$state, ['visit_id' => $id]);
            if ($state === 'cancelled') {
                $this->notifyCustomer($visit['customer_id'], 'Appointment cancelled: '.$visit['job_title'], 'appointments', $visit);
            }

            return $visit;
        });
    }

    public function draftChange(array $input): array
    {
        $job = $this->records->get('jobs', $input['job_id']);
        $this->ensure(($job['currency'] ?? Settings::get('business')['currency'] ?? 'USD') === $input['currency'], 'currency', 'A change order must use the job currency.');
        $this->ensure(! in_array($job['status'] ?? '', ['completed', 'cancelled'], true), 'job_id', 'Reopen this job before changing its scope.');

        return $this->records->create('change_orders', array_merge($input, ['customer_id' => $job['customer_id'], 'job_title' => $job['title'], 'status' => 'draft', 'revision' => 1, 'created_by' => auth()->id()]));
    }

    public function reviseChange(string $id, int $version, array $input): array
    {
        return $this->records->transaction(function () use ($id, $version, $input) {
            $this->records->lock('change:'.$id);
            $change = $this->records->get('change_orders', $id);
            $this->ensure($change['status'] === 'draft', 'status', 'Sent change orders are immutable. Cancel and create a replacement.');

            return $this->records->update('change_orders', $id, array_merge($input, ['revision' => $change['revision'] + 1]), $version);
        });
    }

    public function sendChange(string $id, int $version): array
    {
        return $this->records->transaction(function () use ($id, $version) {
            $this->records->lock('change:'.$id);
            $change = $this->records->get('change_orders', $id);
            if ($change['status'] === 'sent' && ($change['sent_version'] ?? null) === $version) {
                return $change;
            }
            $this->ensure($change['status'] === 'draft' && $change['version'] === $version, 'version', 'This draft changed. Review its current revision.');
            $job = $this->records->get('jobs', $change['job_id']);
            $this->ensure(! in_array($job['status'] ?? '', ['completed', 'cancelled'], true), 'job_id', 'This job is no longer open for changes.');
            $number = 'CO-'.str_pad((string) $this->records->sequence('change-order-number'), 6, '0', STR_PAD_LEFT);
            $snapshot = array_merge($change, ['number' => $number, 'job_title' => $job['title'], 'business' => Settings::get('business')]);
            $change = $this->records->update('change_orders', $id, ['status' => 'sent', 'number' => $number, 'snapshot' => $snapshot, 'sent_version' => $version, 'sent_at' => now()->toISOString()], $version);
            $this->notifyCustomer($change['customer_id'], 'Review change order '.$number.': '.$change['title'], 'approvals');
            Outbox::audit('change_order.sent', ['change_order_id' => $id]);

            return $change;
        });
    }

    public function acceptChange(string $id, int $version, string $actor, string $ip): array
    {
        return $this->records->transaction(function () use ($id, $version, $actor, $ip) {
            $this->records->lock('change:'.$id);
            $change = $this->records->get('change_orders', $id);
            if ($change['status'] === 'accepted' && ($change['accepted_version'] ?? null) === $version) {
                return $change;
            }
            $this->ensure($change['status'] === 'sent' && $change['version'] === $version, 'version', 'Review the exact current change order before accepting.');
            $this->records->lock('job-scope:'.$change['job_id']);
            $job = $this->records->get('jobs', $change['job_id']);
            $this->ensure(! in_array($job['status'] ?? '', ['completed', 'cancelled'], true), 'job_id', 'This job is no longer open for changes.');
            $base = (int) ($job['estimated_total_minor'] ?? 0);
            $total = (int) ($job['approved_change_total_minor'] ?? 0) + $change['amount_minor'];
            $this->ensure($base + $total >= 0 && $base + $total <= Money::MAX_MINOR, 'amount_minor', 'Approved changes cannot make the job total negative or exceed the supported limit.');
            $acceptedAt = now()->toISOString();
            $changes = $job['approved_changes'] ?? [];
            $changes[] = ['id' => $id, 'number' => $change['number'], 'title' => $change['title'], 'description' => $change['description'], 'amount_minor' => $change['amount_minor'], 'currency' => $change['currency'], 'accepted_at' => $acceptedAt];
            $this->records->update('jobs', $job['id'], ['approved_changes' => $changes, 'approved_change_total_minor' => $total, 'approved_total_minor' => $base + $total]);
            $change = $this->records->update('change_orders', $id, ['status' => 'accepted', 'accepted_version' => $version, 'accepted_by' => $actor, 'accepted_ip' => $ip, 'acceptance_source' => auth()->user()?->role === 'client' ? 'client_portal' : 'staff_recorded', 'accepted_at' => $acceptedAt], $version);
            Outbox::audit('change_order.accepted', ['change_order_id' => $id, 'job_id' => $job['id'], 'version' => $version]);
            foreach ($this->records->all('users') as $user) {
                if (in_array($user['role'], ['owner', 'office', 'dispatcher'], true) && ($user['active'] ?? true)) {
                    Outbox::notify($user['id'], 'Change order approved: '.$change['title'], 'approvals', '/app/jobs', 'high');
                }
            }

            return $change;
        });
    }

    public function closeChange(string $id, int $version, string $state, string $reason): array
    {
        return $this->records->transaction(function () use ($id, $version, $state, $reason) {
            $this->records->lock('change:'.$id);
            $change = $this->records->get('change_orders', $id);
            if ($change['status'] === $state && ($change['closed_version'] ?? null) === $version) {
                return $change;
            }
            $this->ensure($change['version'] === $version, 'version', 'This change order changed. Reload before continuing.');
            $this->ensure(in_array($change['status'], $state === 'rejected' ? ['sent'] : ['draft', 'sent'], true), 'status', 'An accepted change order cannot be altered. Create an offsetting change instead.');
            $change = $this->records->update('change_orders', $id, ['status' => $state, 'closed_version' => $version, 'closed_by' => auth()->id(), 'closed_at' => now()->toISOString(), 'reason' => $reason], $version);
            Outbox::audit('change_order.'.$state, ['change_order_id' => $id]);

            return $change;
        });
    }

    public function assertAvailable(Carbon $start, int $duration, array $staff, ?string $jobId = null, ?string $visitId = null, bool $ignoreOwnJobVisits = false): void
    {
        $end = $start->copy()->addMinutes($duration);
        foreach ($this->records->all('visits', ['status' => 'confirmed']) as $visit) {
            if ($visit['id'] === $visitId || ($ignoreOwnJobVisits && $visit['job_id'] === $jobId) || ! array_intersect($staff, $visit['assigned_to'] ?? [])) {
                continue;
            }
            $otherStart = Carbon::parse($visit['start_at']);
            $otherEnd = $otherStart->copy()->addMinutes($visit['duration_minutes']);
            abort_if($start->lt($otherEnd) && $end->gt($otherStart), 409, 'A team member already has a confirmed visit during this time.');
        }
        foreach ($this->records->all('jobs') as $job) {
            if ($job['id'] === $jobId || empty($job['scheduled_at']) || in_array($job['status'] ?? '', ['completed', 'cancelled'], true) || ! array_intersect($staff, $job['assigned_to'] ?? [])) {
                continue;
            }
            $otherStart = Carbon::parse($job['scheduled_at']);
            $otherEnd = $otherStart->copy()->addMinutes($job['duration_minutes'] ?? 60);
            abort_if($start->lt($otherEnd) && $end->gt($otherStart), 409, 'A team member is already assigned to another job during this time.');
        }
    }

    private function validateStaff(array $ids): void
    {
        $this->ensure(count($ids) > 0, 'assigned_to', 'Assign at least one team member before confirming.');
        foreach ($ids as $id) {
            $user = $this->records->get('users', $id);
            $this->ensure(($user['active'] ?? true) && $user['role'] !== 'client', 'assigned_to', 'Assign only active team members.');
        }
    }

    private function notifyCustomer(string $customerId, string $title, string $category, ?array $visit = null): void
    {
        foreach ($this->records->all('users', ['customer_id' => $customerId]) as $user) {
            if ($user['role'] === 'client' && ! empty($user['email_verified_at']) && ($user['active'] ?? true)) {
                $email = $visit ? [
                    'to' => $user['email'],
                    'template_key' => 'appointment',
                    'template_data' => [
                        'recipient_name' => $user['name'] ?? 'there',
                        'service' => $visit['job_title'],
                        'appointment_at' => Carbon::parse($visit['start_at'])->setTimezone(Settings::get('business')['timezone'] ?? 'UTC')->format('M j, Y g:i A T'),
                        'message' => $title,
                        'action_url' => rtrim(config('app.url'), '/').'/app/jobs',
                    ],
                ] : null;
                Outbox::notify($user['id'], $title, $category, '/app/jobs', 'high', $email);
            }
        }
    }

    private function ensure(bool $condition, string $field, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }
}

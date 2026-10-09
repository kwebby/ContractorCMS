<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace App\Http\Controllers;

use App\Contracts\RecordRepository;
use App\Operations\OperationsWorkflow;
use App\Operations\PlanningRelations;
use App\Support\Access;
use App\Support\Outbox;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;

class OperationsController extends Controller
{
    private const RESOURCES = ['leads', 'customers', 'jobs', 'tasks', 'assets', 'agreements', 'projects', 'purchases'];

    /**
     * Inquiry acknowledgements sent to one address per hour, so the public form cannot relay mail.
     */
    private const ACKNOWLEDGEMENTS_PER_HOUR = 3;

    public function __construct(private RecordRepository $records) {}

    public function index(Request $request, string $resource): mixed
    {
        $this->readPermission($request, $resource);
        $data = array_values(array_filter($this->records->all($resource), fn (array $r): bool => $this->visible($request, $resource, $r)));
        if ($search = mb_strtolower($request->string('search')->toString())) {
            $data = array_values(array_filter($data, fn (array $r): bool => str_contains(mb_strtolower(json_encode(Arr::only($r, ['name', 'title', 'email', 'phone', 'address', 'customer_name']))), $search)));
        }

        return response()->json(['data' => array_map(fn (array $record): array => Access::publicRecord($request->user(), $resource, $record), $data)]);
    }

    public function show(Request $request, string $resource, string $id): mixed
    {
        $this->readPermission($request, $resource);
        $record = $this->records->get($resource, $id);
        abort_unless($this->visible($request, $resource, $record), 403);

        return response()->json(['data' => Access::publicRecord($request->user(), $resource, $record)]);
    }

    public function store(Request $request, string $resource): mixed
    {
        return $this->save($request, $resource);
    }

    public function update(Request $request, string $resource, string $id): mixed
    {
        return $this->save($request, $resource, $id);
    }

    private function save(Request $request, string $resource, ?string $id = null): mixed
    {
        abort_unless(in_array($resource, self::RESOURCES, true), 404);
        $permission = in_array($resource, ['leads', 'customers']) ? 'crm.manage' : 'operations.manage';
        if ($resource === 'tasks') {
            abort_if($request->user()->role === 'client', 403);
            if ($request->user()->role === 'technician') {
                abort_unless($id, 403);
                Access::authorizeRecord($request->user(), $this->records->get('tasks', $id), 'operations.assigned');
                $data = $request->validate([
                    'status' => 'sometimes|in:open,in_progress,blocked,done',
                    'notes' => 'sometimes|nullable|string|max:10000',
                    'checklist' => 'sometimes|array|max:50',
                    'checklist.*' => 'array:label,done',
                    'checklist.*.label' => 'required|string|max:200',
                    'checklist.*.done' => 'required|boolean',
                    'expected_version' => 'required|integer|min:1',
                    'title' => 'missing',
                    'assigned_to' => 'missing',
                    'due_at' => 'missing',
                    'priority' => 'missing',
                    'customer_id' => 'missing',
                    'lead_id' => 'missing',
                    'job_id' => 'missing',
                ]);
            } else {
                abort_unless(Access::can($request->user(), 'operations.manage') || Access::can($request->user(), 'crm.manage'), 403);
                $data = $request->validate($this->rules($resource, $id !== null));
            }
        } elseif ($request->user()->role === 'technician' && $resource === 'jobs' && $id) {
            Access::authorizeRecord($request->user(), $this->records->get('jobs', $id), 'operations.assigned');
            $data = $request->validate(['status' => 'sometimes|in:in_progress,blocked,completed', 'field_notes' => 'sometimes|nullable|string|max:10000', 'checklist' => 'sometimes|array|max:50', 'checklist.*.label' => 'required|string|max:200', 'checklist.*.done' => 'required|boolean', 'time_minutes' => 'sometimes|integer|min:0|max:1440', 'completion_requested' => 'sometimes|boolean', 'expected_version' => 'sometimes|integer|min:1']);
            if (($data['status'] ?? '') === 'completed') {
                $data['status'] = 'in_progress';
                $data['completion_requested'] = true;
            }
        } else {
            Access::authorize($permission);
            $data = $request->validate($this->rules($resource, $id !== null));
        }
        if (isset($data['email'])) {
            $data['email'] = strtolower($data['email']);
        }
        if (isset($data['customer_id'])) {
            $customer = $resource === 'tasks' ? $this->records->find('customers', $data['customer_id']) : $this->records->get('customers', $data['customer_id']);
            abort_unless($customer, 422, 'Choose an existing customer.');
            $data['customer_name'] = $customer['name'];
        } elseif ($resource === 'tasks' && array_key_exists('customer_id', $data)) {
            $data['customer_name'] = null;
        }
        if ($resource === 'jobs' && array_key_exists('property_id', $data) && $data['property_id']) {
            $property = $this->records->get('properties', $data['property_id']);
            $customerId = $data['customer_id'] ?? ($id ? ($this->records->get('jobs', $id)['customer_id'] ?? null) : null);
            abort_unless($property['customer_id'] === $customerId && ($property['status'] ?? '') !== 'archived', 422, 'Choose an active property belonging to this customer.');
        }
        if (isset($data['assigned_to']) && is_array($data['assigned_to'])) {
            foreach ($data['assigned_to'] as $uid) {
                $staff = $this->records->find('users', $uid);
                abort_unless($staff, 422, 'Choose an existing team member.');
                abort_if($staff['role'] === 'client' || ! ($staff['active'] ?? true), 422, 'Assign an active team member.');
            }
        }
        $version = Arr::pull($data, 'expected_version');
        $record = $this->records->transaction(function () use ($request, $resource, $data, $id, $version): array {
            if ($resource === 'tasks') {
                if ($id) {
                    $this->records->lock('planning:task:'.$id);
                }
                $existing = $id ? $this->records->get('tasks', $id) : [];
                if ($request->user()->role === 'technician') {
                    Access::authorizeRecord($request->user(), $existing, 'operations.assigned');
                }
                app(PlanningRelations::class)->validate(array_replace($existing, $data));
            }
            if ($resource === 'jobs') {
                $this->records->lock('schedule');
                $existing = $id ? $this->records->get($resource, $id) : [];
                if ($id && isset($data['customer_id'])) {
                    abort_unless($data['customer_id'] === $existing['customer_id'], 422, 'A job cannot move between customer accounts. Create a new job instead.');
                }
                $merged = array_replace($existing, $data);
                if (! empty($merged['property_id'])) {
                    $property = $this->records->get('properties', $merged['property_id']);
                    abort_unless($property['customer_id'] === ($merged['customer_id'] ?? null) && ($property['status'] ?? '') !== 'archived', 422, 'Choose an active property belonging to this customer.');
                }
                if (! empty($merged['scheduled_at']) && ! in_array($merged['status'] ?? 'scheduled', ['cancelled', 'completed'])) {
                    $start = Carbon::parse($merged['scheduled_at']);
                    $end = $start->copy()->addMinutes($merged['duration_minutes'] ?? 60);
                    app(OperationsWorkflow::class)->assertAvailable($start, (int) ($merged['duration_minutes'] ?? 60), $merged['assigned_to'] ?? [], $id, null, true);
                    foreach ($this->records->all('jobs') as $job) {
                        if ($job['id'] === $id || empty($job['scheduled_at']) || in_array($job['status'] ?? '', ['cancelled', 'completed']) || ! array_intersect($job['assigned_to'] ?? [], $merged['assigned_to'] ?? [])) {
                            continue;
                        }
                        $otherStart = Carbon::parse($job['scheduled_at']);
                        $otherEnd = $otherStart->copy()->addMinutes($job['duration_minutes'] ?? 60);
                        abort_if($start->lt($otherEnd) && $end->gt($otherStart), 409, 'A team member is already assigned during this time.');
                    }
                }
            }
            $record = $id ? $this->records->update($resource, $id, $data, $version) : $this->records->create($resource, array_merge($this->defaults($resource), $data));
            Outbox::audit($resource.'.'.($id ? 'updated' : 'created'), ['record_id' => $record['id']]);

            return $record;
        });

        return response()->json(['data' => Access::publicRecord($request->user(), $resource, $record)], $id ? 200 : 201);
    }

    public function publicLead(Request $request): mixed
    {
        if (! $request->has('notes') && $request->has('message')) {
            $request->merge(['notes' => $request->input('message')]);
        }
        $data = $request->validate(['name' => 'required|string|max:120', 'email' => 'required|email|max:254', 'phone' => 'nullable|string|max:40', 'service' => 'required|string|max:100', 'notes' => 'nullable|string|max:5000', 'address' => 'nullable|string|max:500', 'marketing_consent' => 'sometimes|boolean', 'website' => 'nullable|max:0']);
        unset($data['website']);
        $data['email'] = strtolower($data['email']);
        $lead = $this->records->create('leads', array_merge($data, ['stage' => 'new', 'priority' => 'normal', 'source' => 'website', 'marketing_consent_at' => ($data['marketing_consent'] ?? false) ? now()->toISOString() : null]));
        foreach ($this->records->all('users', ['role' => 'owner']) as $user) {
            Outbox::notify($user['id'], 'New inquiry from '.$data['name'], 'leads', '/app/leads', 'high');
        }
        if (RateLimiter::attempt('lead-ack:'.hash('sha256', $data['email']), self::ACKNOWLEDGEMENTS_PER_HOUR, fn (): bool => true, 3600)) {
            Outbox::push('email', ['to' => $data['email'], 'template_key' => 'inquiry', 'template_data' => ['recipient_name' => $data['name'], 'service' => $data['service']]], 'lead-ack:'.$lead['id']);
        }

        return response()->json(['message' => 'Request received. Our office will contact you to confirm the next step.'], 201);
    }

    private function readPermission(Request $request, string $resource): void
    {
        abort_unless(in_array($resource, self::RESOURCES, true), 404);
        if ($request->user()->role === 'client') {
            abort_unless(in_array($resource, ['jobs', 'assets', 'agreements', 'projects']) && $request->user()->email_verified_at, 403);

            return;
        }
        if ($request->user()->role === 'technician') {
            abort_unless(in_array($resource, ['jobs', 'tasks']), 403);

            return;
        }
        if ($resource === 'tasks' && (Access::can($request->user(), 'crm.manage') || Access::can($request->user(), 'crm.view'))) {
            return;
        }
        $base = in_array($resource, ['leads', 'customers']) ? 'crm' : 'operations';
        abort_unless(Access::can($request->user(), $base.'.manage') || Access::can($request->user(), $base.'.view') || Access::can($request->user(), 'reports.view'), 403);
    }

    private function visible(Request $request, string $resource, array $record): bool
    {
        if ($request->user()->role === 'client') {
            return $request->user()->customer_id && ($record['customer_id'] ?? null) === $request->user()->customer_id;
        }
        if ($request->user()->role === 'technician') {
            return in_array($request->user()->id, (array) ($record['assigned_to'] ?? []), true);
        }

        return true;
    }

    private function defaults(string $resource): array
    {
        return match ($resource) {
            'leads' => ['stage' => 'new', 'priority' => 'normal', 'source' => 'manual'],'jobs' => ['status' => 'scheduled', 'priority' => 'normal', 'assigned_to' => [], 'duration_minutes' => 60],'tasks' => ['status' => 'open', 'priority' => 'normal', 'assigned_to' => [], 'checklist' => [], 'due_at' => null],default => ['status' => 'active']
        };
    }

    private function rules(string $resource, bool $patch): array
    {
        $required = $patch ? 'sometimes' : 'required';
        $common = ['expected_version' => 'sometimes|integer|min:1', 'notes' => 'sometimes|nullable|string|max:10000'];
        if ($resource === 'tasks' && $patch) {
            $common['expected_version'] = 'required|integer|min:1';
        }

        return $common + match ($resource) {
            'leads' => ['name' => $required.'|string|max:120', 'email' => 'sometimes|nullable|email|max:254', 'phone' => 'sometimes|nullable|string|max:40', 'service' => 'sometimes|nullable|string|max:100', 'source' => 'sometimes|string|max:80', 'stage' => 'sometimes|in:new,contacted,qualified,estimate_sent,won,lost', 'priority' => 'sometimes|in:low,normal,high,urgent', 'assigned_to' => 'sometimes|nullable|string|max:128', 'follow_up_at' => 'sometimes|nullable|date', 'address' => 'sometimes|nullable|string|max:500'],
            'customers' => ['name' => $required.'|string|max:120', 'email' => 'sometimes|nullable|email|max:254', 'phone' => 'sometimes|nullable|string|max:40', 'address' => 'sometimes|nullable|string|max:500', 'city' => 'sometimes|nullable|string|max:100', 'region' => 'sometimes|nullable|string|max:100', 'postal_code' => 'sometimes|nullable|string|max:20', 'country' => 'sometimes|in:US,CA', 'status' => 'sometimes|in:active,inactive,new'],
            'jobs' => ['property_id' => 'sometimes|nullable|string|max:128', 'title' => $required.'|string|max:180', 'customer_id' => $required.'|string|max:128', 'service' => 'sometimes|nullable|string|max:100', 'status' => 'sometimes|in:scheduled,in_progress,blocked,completed,cancelled', 'priority' => 'sometimes|in:low,normal,high,urgent', 'assigned_to' => 'sometimes|array|max:20', 'assigned_to.*' => 'string|max:128', 'scheduled_at' => 'sometimes|nullable|date', 'duration_minutes' => 'sometimes|integer|min:15|max:1440', 'description' => 'sometimes|nullable|string|max:10000', 'address' => 'sometimes|nullable|string|max:500', 'field_notes' => 'sometimes|nullable|string|max:10000', 'completion_requested' => 'sometimes|boolean', 'checklist' => 'sometimes|array|max:50', 'checklist.*.label' => 'required|string|max:200', 'checklist.*.done' => 'required|boolean', 'actual_cost_minor' => 'sometimes|integer|min:0|max:100000000'],
            'tasks' => ['title' => $required.'|string|max:180', 'status' => 'sometimes|in:open,in_progress,blocked,done', 'assigned_to' => 'sometimes|array|max:20', 'assigned_to.*' => 'required|string|max:128|distinct', 'due_at' => 'sometimes|nullable|date|after_or_equal:2000-01-01|before:2101-01-01', 'priority' => 'sometimes|in:low,normal,high,urgent', 'job_id' => 'sometimes|nullable|string|max:128', 'customer_id' => 'sometimes|nullable|string|max:128', 'lead_id' => 'sometimes|nullable|string|max:128', 'checklist' => 'sometimes|array|max:50', 'checklist.*' => 'array:label,done', 'checklist.*.label' => 'required|string|max:200', 'checklist.*.done' => 'required|boolean'],
            default => ['name' => $required.'|string|max:180', 'title' => 'sometimes|string|max:180', 'customer_id' => 'sometimes|nullable|string|max:128', 'status' => 'sometimes|string|max:50', 'description' => 'sometimes|nullable|string|max:10000', 'details' => 'sometimes|array|max:30'],
        };
    }
}

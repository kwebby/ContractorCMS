<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace App\Http\Controllers;

use App\Contracts\RecordRepository;
use App\Operations\OperationsWorkflow;
use App\Support\Access;
use App\Support\Outbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class OperationsWorkflowController extends Controller
{
    public function __construct(private RecordRepository $records, private OperationsWorkflow $workflow) {}

    public function properties(Request $request): JsonResponse
    {
        $this->readAccess($request, true);
        $where = $request->filled('customer_id') ? ['customer_id' => (string) $request->input('customer_id')] : [];
        $records = array_values(array_filter($this->records->all('properties', $where), fn (array $record) => $this->visibleProperty($request, $record) && (($record['status'] ?? '') !== 'archived' || $request->boolean('include_archived'))));

        return response()->json(['data' => array_map(fn (array $record) => $this->propertyView($request, $record), $records)]);
    }

    public function property(Request $request, string $id): JsonResponse
    {
        $this->readAccess($request, true);
        $record = $this->records->get('properties', $id);
        abort_unless($this->visibleProperty($request, $record), 403);

        return response()->json(['data' => $this->propertyView($request, $record)]);
    }

    public function saveProperty(Request $request, ?string $id = null): JsonResponse
    {
        $client = $request->user()->role === 'client';
        if ($client) {
            $this->verifiedClient($request);
        } else {
            abort_unless(Access::can($request->user(), 'crm.manage') || Access::can($request->user(), 'operations.manage'), 403);
        }
        $required = $id ? 'sometimes' : 'required';
        $rules = ['name' => $required.'|string|max:180', 'address' => $required.'|string|max:500', 'city' => 'sometimes|nullable|string|max:100', 'region' => 'sometimes|nullable|string|max:100', 'postal_code' => 'sometimes|nullable|string|max:20', 'country' => $required.'|in:US,CA', 'access_notes' => 'sometimes|nullable|string|max:3000'];
        if (! $client && ! $id) {
            $rules['customer_id'] = 'required|string|max:128';
        }
        if (! $client) {
            $rules['internal_notes'] = 'sometimes|nullable|string|max:5000';
        }
        if ($id) {
            $rules['version'] = 'required|integer|min:1';
        }
        $input = $request->validate($rules);
        if ($id) {
            $record = $this->records->get('properties', $id);
            abort_unless($this->visibleProperty($request, $record), 403);
            $version = Arr::pull($input, 'version');
            $record = $this->records->update('properties', $id, $input, $version);
        } else {
            $customerId = $client ? $request->user()->customer_id : $input['customer_id'];
            $customer = $this->records->get('customers', $customerId);
            $record = $this->records->create('properties', array_merge($input, ['customer_id' => $customerId, 'customer_name' => $customer['name'], 'status' => 'active', 'created_by' => $request->user()->id]));
        }
        Outbox::audit('property.saved', ['property_id' => $record['id']]);

        return response()->json(['data' => $this->propertyView($request, $record)], $id ? 200 : 201);
    }

    public function archiveProperty(Request $request, string $id): JsonResponse
    {
        if ($request->user()->role === 'client') {
            $this->verifiedClient($request);
        } else {
            abort_unless(Access::can($request->user(), 'crm.manage') || Access::can($request->user(), 'operations.manage'), 403);
        }
        $version = $request->validate(['version' => 'required|integer|min:1'])['version'];
        $record = $this->records->transaction(function () use ($request, $id, $version) {
            $this->records->lock('schedule');
            $property = $this->records->get('properties', $id);
            abort_unless($this->visibleProperty($request, $property), 403);
            foreach ($this->records->all('jobs', ['property_id' => $id]) as $job) {
                abort_unless(in_array($job['status'] ?? '', ['completed', 'cancelled'], true), 422, 'Close active jobs before archiving this property.');
            }

            return $this->records->update('properties', $id, ['status' => 'archived'], $version);
        });

        return response()->json(['data' => $this->propertyView($request, $record)]);
    }

    public function visits(Request $request): JsonResponse
    {
        return $this->jobRecords($request, 'visits');
    }

    public function visit(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->scoped($request, 'visits', $id)]);
    }

    public function createVisit(Request $request): JsonResponse
    {
        $client = $request->user()->role === 'client';
        if ($client) {
            $this->verifiedClient($request);
        } else {
            Access::authorize('operations.manage');
        }
        $input = $request->validate(['job_id' => 'required|string|max:128', 'start_at' => $this->dateRules(), 'duration_minutes' => 'required|integer|min:15|max:1440', 'purpose' => 'required|string|max:2000', 'assigned_to' => 'sometimes|array|max:20', 'assigned_to.*' => 'string|max:128']);
        $job = $this->records->get('jobs', $input['job_id']);
        Access::authorizeRecord($request->user(), $job, 'operations.manage');
        $input['assigned_to'] = $client ? ($job['assigned_to'] ?? []) : ($input['assigned_to'] ?? []);

        return response()->json(['data' => $this->workflow->requestVisit($input)], 201);
    }

    public function confirmVisit(Request $request, string $id): JsonResponse
    {
        Access::authorize('operations.manage');
        $input = $request->validate(['version' => 'required|integer|min:1', 'start_at' => $this->dateRules(false), 'duration_minutes' => 'sometimes|integer|min:15|max:1440', 'assigned_to' => 'sometimes|array|min:1|max:20', 'assigned_to.*' => 'string|max:128']);
        $version = Arr::pull($input, 'version');

        return response()->json(['data' => $this->workflow->confirmVisit($id, $version, $input)]);
    }

    public function cancelVisit(Request $request, string $id): JsonResponse
    {
        $visit = $this->scoped($request, 'visits', $id);
        if ($request->user()->role === 'client') {
            abort_unless($visit['status'] === 'requested' || $visit['status'] === 'cancelled', 403, 'Contact the office to change a confirmed appointment.');
        } else {
            Access::authorize('operations.manage');
        }
        $input = $request->validate(['version' => 'required|integer|min:1', 'reason' => 'sometimes|nullable|string|max:2000']);

        return response()->json(['data' => $this->workflow->closeVisit($id, $input['version'], 'cancelled', $input['reason'] ?? '')]);
    }

    public function completeVisit(Request $request, string $id): JsonResponse
    {
        Access::authorize('operations.manage');
        $input = $request->validate(['version' => 'required|integer|min:1']);

        return response()->json(['data' => $this->workflow->closeVisit($id, $input['version'], 'completed', '')]);
    }

    public function changes(Request $request): JsonResponse
    {
        return $this->jobRecords($request, 'change_orders');
    }

    public function change(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->scoped($request, 'change_orders', $id)]);
    }

    public function createChange(Request $request): JsonResponse
    {
        Access::authorize('operations.manage');
        $input = $request->validate($this->changeRules() + ['job_id' => 'required|string|max:128', 'currency' => 'required|in:USD,CAD']);

        $input['amount_minor'] = (int) $input['amount_minor'];

        return response()->json(['data' => $this->workflow->draftChange($input)], 201);
    }

    public function reviseChange(Request $request, string $id): JsonResponse
    {
        Access::authorize('operations.manage');
        $input = $request->validate($this->changeRules() + ['version' => 'required|integer|min:1']);
        $version = Arr::pull($input, 'version');

        $input['amount_minor'] = (int) $input['amount_minor'];

        return response()->json(['data' => $this->workflow->reviseChange($id, $version, $input)]);
    }

    public function sendChange(Request $request, string $id): JsonResponse
    {
        Access::authorize('operations.manage');
        $version = $request->validate(['version' => 'required|integer|min:1'])['version'];

        return response()->json(['data' => $this->workflow->sendChange($id, $version)]);
    }

    public function acceptChange(Request $request, string $id): JsonResponse
    {
        $this->scoped($request, 'change_orders', $id);
        if ($request->user()->role !== 'client') {
            Access::authorize('operations.manage');
        }
        $version = $request->validate(['version' => 'required|integer|min:1'])['version'];

        return response()->json(['data' => $this->workflow->acceptChange($id, $version, $request->user()->id, $request->ip())]);
    }

    public function rejectChange(Request $request, string $id): JsonResponse
    {
        $this->scoped($request, 'change_orders', $id);
        if ($request->user()->role !== 'client') {
            Access::authorize('operations.manage');
        }
        $input = $request->validate(['version' => 'required|integer|min:1', 'reason' => 'sometimes|nullable|string|max:2000']);

        return response()->json(['data' => $this->workflow->closeChange($id, $input['version'], 'rejected', $input['reason'] ?? '')]);
    }

    public function cancelChange(Request $request, string $id): JsonResponse
    {
        Access::authorize('operations.manage');
        $input = $request->validate(['version' => 'required|integer|min:1', 'reason' => 'sometimes|nullable|string|max:2000']);

        return response()->json(['data' => $this->workflow->closeChange($id, $input['version'], 'cancelled', $input['reason'] ?? '')]);
    }

    public function branches(): JsonResponse
    {
        Access::authorize('settings.manage');

        return response()->json(['data' => $this->records->all('branches')]);
    }

    public function saveBranch(Request $request, ?string $id = null): JsonResponse
    {
        Access::authorize('settings.manage');
        $rules = ['name' => 'required|string|max:180', 'address' => 'required|string|max:500', 'city' => 'required|string|max:100', 'region' => 'required|string|max:100', 'postal_code' => 'required|string|max:20', 'country' => 'required|in:US,CA', 'timezone' => 'required|timezone', 'phone' => 'sometimes|nullable|string|max:40', 'status' => 'required|in:active,inactive', 'verified_location' => 'required|boolean'];
        if ($id) {
            $rules['version'] = 'required|integer|min:1';
        }
        $input = $request->validate($rules);
        $version = Arr::pull($input, 'version');
        $record = $id ? $this->records->update('branches', $id, $input, $version) : $this->records->create('branches', $input);
        Outbox::audit('branch.saved', ['branch_id' => $record['id']]);

        return response()->json(['data' => $record], $id ? 200 : 201);
    }

    private function jobRecords(Request $request, string $collection): JsonResponse
    {
        $this->readAccess($request);
        $where = $request->filled('job_id') ? ['job_id' => (string) $request->input('job_id')] : [];
        $records = array_values(array_filter($this->records->all($collection, $where), function (array $record) use ($request, $collection): bool {
            if ($request->user()->role === 'client') {
                return $record['customer_id'] === $request->user()->customer_id && ($collection !== 'change_orders' || $record['status'] !== 'draft');
            }
            if ($request->user()->role === 'technician') {
                $job = $this->records->find('jobs', $record['job_id']);

                return $job && in_array($request->user()->id, $job['assigned_to'] ?? [], true) && ($collection !== 'change_orders' || $record['status'] === 'accepted');
            }

            return true;
        }));

        return response()->json(['data' => $records]);
    }

    private function scoped(Request $request, string $collection, string $id): array
    {
        $this->readAccess($request);
        $record = $this->records->get($collection, $id);
        $job = $this->records->get('jobs', $record['job_id']);
        if ($request->user()->role === 'technician') {
            Access::authorizeRecord($request->user(), $job, 'operations.assigned');
            abort_if($collection === 'change_orders' && $record['status'] !== 'accepted', 403);
        } else {
            Access::authorizeRecord($request->user(), $record, Access::can($request->user(), 'operations.manage') ? 'operations.manage' : 'operations.view');
        }
        if ($request->user()->role === 'client') {
            abort_if($collection === 'change_orders' && $record['status'] === 'draft', 403);
        }

        return $record;
    }

    private function visibleProperty(Request $request, array $property): bool
    {
        if ($request->user()->role === 'client') {
            return $property['customer_id'] === $request->user()->customer_id;
        }
        if ($request->user()->role === 'technician') {
            foreach ($this->records->all('jobs', ['property_id' => $property['id']]) as $job) {
                if (in_array($request->user()->id, $job['assigned_to'] ?? [], true)) {
                    return true;
                }
            }

            return false;
        }

        return true;
    }

    private function propertyView(Request $request, array $record): array
    {
        return $request->user()->role === 'client' ? Arr::except($record, ['internal_notes', 'created_by']) : $record;
    }

    private function readAccess(Request $request, bool $property = false): void
    {
        if ($request->user()->role === 'client') {
            $this->verifiedClient($request);

            return;
        }
        abort_unless($request->user()->role === 'technician' || Access::can($request->user(), 'operations.manage') || Access::can($request->user(), 'operations.view') || ($property && (Access::can($request->user(), 'crm.manage') || Access::can($request->user(), 'crm.view'))), 403);
    }

    private function verifiedClient(Request $request): void
    {
        abort_unless($request->user()->email_verified_at && $request->user()->customer_id, 403, 'Verify your email before using the client portal.');
    }

    private function dateRules(bool $required = true): array
    {
        return [$required ? 'required' : 'sometimes', 'date', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?(?:Z|[+-]\d{2}:\d{2})$/D'];
    }

    private function changeRules(): array
    {
        return ['title' => 'required|string|max:180', 'description' => 'required|string|max:10000', 'amount_minor' => 'required|integer|min:-1000000000000|max:1000000000000'];
    }
}

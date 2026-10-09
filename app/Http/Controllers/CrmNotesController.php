<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace App\Http\Controllers;

use App\Contracts\RecordRepository;
use App\Operations\PlanningRelations;
use App\Support\Access;
use App\Support\Outbox;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class CrmNotesController extends Controller
{
    public function __construct(private RecordRepository $records, private PlanningRelations $relations) {}

    public function index(Request $request): mixed
    {
        $this->authorize($request);
        $filters = $request->validate([
            'from' => 'sometimes|date_format:Y-m-d|after_or_equal:2000-01-01|before:2101-01-01',
            'to' => 'sometimes|date_format:Y-m-d|after_or_equal:2000-01-01|before:2101-01-01',
            'customer_id' => 'sometimes|string|max:128',
            'lead_id' => 'sometimes|string|max:128',
            'job_id' => 'sometimes|string|max:128',
        ]);
        abort_if(isset($filters['from'], $filters['to']) && $filters['from'] > $filters['to'], 422, 'The end date must follow the start date.');
        $notes = array_values(array_filter($this->records->all('crm_notes', Arr::only($filters, ['customer_id', 'lead_id', 'job_id'])), function (array $note) use ($filters): bool {
            return (! isset($filters['from']) || $note['note_date'] >= $filters['from'])
                && (! isset($filters['to']) || $note['note_date'] <= $filters['to']);
        }));
        usort($notes, fn (array $first, array $second): int => [$first['note_date'], $first['created_at'], $first['id']] <=> [$second['note_date'], $second['created_at'], $second['id']]);

        return response()->json(['data' => $notes]);
    }

    public function show(Request $request, string $id): mixed
    {
        $this->authorize($request);

        return response()->json(['data' => $this->records->get('crm_notes', $id)]);
    }

    public function store(Request $request): mixed
    {
        return $this->save($request);
    }

    public function update(Request $request, string $id): mixed
    {
        return $this->save($request, $id);
    }

    private function save(Request $request, ?string $id = null): mixed
    {
        $this->authorize($request, true);
        $required = $id ? 'sometimes' : 'required';
        $data = $request->validate([
            'title' => $required.'|string|max:180',
            'body' => $required.'|string|max:10000',
            'note_date' => $required.'|date_format:Y-m-d|after_or_equal:2000-01-01|before:2101-01-01',
            'customer_id' => 'sometimes|nullable|string|max:128',
            'lead_id' => 'sometimes|nullable|string|max:128',
            'job_id' => 'sometimes|nullable|string|max:128',
            'expected_version' => ($id ? 'required' : 'prohibited').'|integer|min:1',
        ]);
        $version = Arr::pull($data, 'expected_version');
        $note = $this->records->transaction(function () use ($request, $id, $data, $version): array {
            if ($id) {
                $this->records->lock('planning:note:'.$id);
            }
            $existing = $id ? $this->records->get('crm_notes', $id) : [];
            $this->relations->validate(array_replace($existing, $data));
            $note = $id
                ? $this->records->update('crm_notes', $id, $data, $version)
                : $this->records->create('crm_notes', $data + ['created_by' => $request->user()->id]);
            Outbox::audit('crm_notes.'.($id ? 'updated' : 'created'), ['record_id' => $note['id']]);

            return $note;
        });

        return response()->json(['data' => $note], $id ? 200 : 201);
    }

    private function authorize(Request $request, bool $write = false): void
    {
        abort_if(in_array($request->user()->role, ['client', 'technician'], true), 403);
        $permissions = $write ? ['crm.manage', 'operations.manage'] : ['crm.view', 'crm.manage', 'operations.view', 'operations.manage'];
        foreach ($permissions as $permission) {
            if (Access::can($request->user(), $permission)) {
                return;
            }
        }
        abort(403, 'You do not have permission for CRM notes.');
    }
}

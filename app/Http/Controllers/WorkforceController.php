<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace App\Http\Controllers;

use App\Contracts\RecordRepository;
use App\Finance\DocumentPdf;
use App\Finance\Money;
use App\Support\Access;
use App\Workforce\PayrollService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WorkforceController extends Controller
{
    public function __construct(private RecordRepository $records, private PayrollService $payroll) {}

    public function runs(Request $request): JsonResponse
    {
        abort_if($request->user()->role === 'client', 403);
        $runs = Access::can($request->user(), 'payroll.manage') ? $this->records->all('pay_runs') : $this->records->all('pay_runs', ['employee_user_id' => $request->user()->id, 'status' => 'published']);

        return response()->json(['data' => $runs]);
    }

    public function run(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->visibleRun($request, $id)]);
    }

    public function createRun(Request $request): JsonResponse
    {
        Access::authorize('payroll.manage');

        return response()->json(['data' => $this->payroll->draft($this->runInput($request))], 201);
    }

    public function reviseRun(Request $request, string $id): JsonResponse
    {
        Access::authorize('payroll.manage');
        $input = $this->runInput($request, true);
        $version = $request->validate(['version' => 'required|integer|min:1'])['version'];

        return response()->json(['data' => $this->payroll->revise($id, $input, $version)]);
    }

    public function review(string $id): JsonResponse
    {
        Access::authorize('payroll.manage');

        return response()->json(['data' => $this->payroll->transition($id, 'review')]);
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        Access::authorize('payroll.manage');
        abort_unless((int) $request->session()->get('auth.password_confirmed_at', 0) >= now()->subMinutes(15)->timestamp, 423, 'Confirm your password before approving payroll.');

        return response()->json(['data' => $this->payroll->transition($id, 'approved')]);
    }

    public function publish(string $id): JsonResponse
    {
        Access::authorize('payroll.manage');

        return response()->json(['data' => $this->payroll->transition($id, 'published')]);
    }

    public function pdf(Request $request, string $id, DocumentPdf $pdf): BinaryFileResponse
    {
        $run = $this->visibleRun($request, $id);

        return response()->download($pdf->payslip($run), ($run['number'] ?? 'payslip').'.pdf', ['Cache-Control' => 'private, no-store', 'X-Robots-Tag' => 'noindex, nofollow']);
    }

    public function compensation(Request $request): JsonResponse
    {
        abort_if($request->user()->role === 'client', 403);
        $where = Access::can($request->user(), 'payroll.manage') ? [] : ['employee_user_id' => $request->user()->id];

        return response()->json(['data' => $this->records->all('compensation', $where)]);
    }

    public function createCompensation(Request $request): JsonResponse
    {
        Access::authorize('payroll.manage');
        $input = $request->validate(['employee_user_id' => 'required|string|max:100', 'type' => 'required|in:hourly,salary', 'rate_minor' => 'required|integer|min:0|max:'.Money::MAX_MINOR, 'currency' => 'required|in:USD,CAD', 'frequency' => 'required|in:hour,week,biweekly,semimonthly,month,year', 'effective_from' => 'required|date_format:Y-m-d', 'effective_to' => 'nullable|date_format:Y-m-d|after_or_equal:effective_from', 'notes' => 'nullable|string|max:2000']);

        return response()->json(['data' => $this->payroll->compensation($input)], 201);
    }

    public function endCompensation(Request $request, string $id): JsonResponse
    {
        Access::authorize('payroll.manage');
        $input = $request->validate(['effective_to' => 'required|date_format:Y-m-d', 'version' => 'required|integer|min:1']);
        $record = $this->records->transaction(function () use ($id, $input) {
            $record = $this->records->get('compensation', $id);
            $this->records->lock('compensation:'.$record['employee_user_id']);
            $record = $this->records->get('compensation', $id);
            abort_unless($input['effective_to'] >= $record['effective_from'], 422, 'End date must be after the effective start.');
            foreach ($this->records->all('compensation', ['employee_user_id' => $record['employee_user_id']]) as $other) {
                if ($other['id'] !== $id) {
                    $overlap = $record['effective_from'] <= ($other['effective_to'] ?? '9999-12-31') && $other['effective_from'] <= $input['effective_to'];
                    abort_if($overlap, 422, 'Compensation periods cannot overlap.');
                }
            }

            return $this->records->update('compensation', $id, ['effective_to' => $input['effective_to']], $input['version']);
        });

        return response()->json(['data' => $record]);
    }

    public function timesheets(Request $request): JsonResponse
    {
        abort_if($request->user()->role === 'client', 403);
        $where = Access::can($request->user(), 'payroll.manage') ? [] : ['employee_user_id' => $request->user()->id];

        return response()->json(['data' => $this->records->all('timesheets', $where)]);
    }

    public function createTimesheet(Request $request): JsonResponse
    {
        abort_if($request->user()->role === 'client', 403);
        $input = $request->validate(['employee_user_id' => 'sometimes|string|max:100', 'job_id' => 'required|string|max:100', 'worked_on' => 'required|date_format:Y-m-d|before_or_equal:today', 'minutes' => 'required|integer|min:1|max:1440', 'description' => 'required|string|max:2000']);
        if (($input['employee_user_id'] ?? $request->user()->id) !== $request->user()->id) {
            Access::authorize('payroll.manage');
        }

        return response()->json(['data' => $this->payroll->timesheet($input, $request->user()->id)], 201);
    }

    public function approveTimesheet(string $id): JsonResponse
    {
        Access::authorize('payroll.manage');
        $record = $this->records->get('timesheets', $id);
        abort_unless($record['status'] === 'submitted', 422, 'Only submitted entries can be approved.');

        return response()->json(['data' => $this->records->update('timesheets', $id, ['status' => 'approved', 'approved_by' => auth()->id(), 'approved_at' => now()->toIso8601String()], $record['version'])]);
    }

    public function export(Request $request): StreamedResponse
    {
        Access::authorize('payroll.manage');
        $input = $request->validate(['from' => 'sometimes|date_format:Y-m-d', 'to' => 'sometimes|date_format:Y-m-d|after_or_equal:from']);
        $runs = array_filter($this->records->all('pay_runs'), fn (array $run) => $run['status'] === 'published' && empty($run['superseded_by']) && $run['payment_date'] >= ($input['from'] ?? '0000-01-01') && $run['payment_date'] <= ($input['to'] ?? '9999-12-31'));

        return response()->streamDownload(function () use ($runs) {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['number', 'employee_user_id', 'employee_name', 'period_start', 'period_end', 'payment_date', 'currency', 'gross_minor', 'deductions_minor', 'reimbursements_minor', 'net_minor'], ',', '"', '');
            foreach ($runs as $run) {
                $row = [$run['number'], $run['employee_user_id'], $run['snapshot']['employee']['name'], $run['period_start'], $run['period_end'], $run['payment_date'], $run['currency'], $run['gross_minor'], $run['deductions_minor'], $run['reimbursements_minor'], $run['net_minor']];
                fputcsv($output, array_map(fn ($value) => is_string($value) && preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value, $row), ',', '"', '');
            }
            fclose($output);
        }, 'payroll-export.csv', ['Content-Type' => 'text/csv', 'Cache-Control' => 'private, no-store', 'X-Robots-Tag' => 'noindex, nofollow']);
    }

    public function import(Request $request): JsonResponse
    {
        Access::authorize('payroll.manage');
        $request->validate(['file' => 'required|file|max:1024|mimes:csv,txt']);
        $stream = fopen($request->file('file')->getRealPath(), 'r');
        $headers = fgetcsv($stream, escape: '');
        $required = ['employee_user_id', 'period_start', 'period_end', 'payment_date', 'currency', 'gross_minor', 'deductions_minor'];
        abort_unless(is_array($headers) && count(array_unique($headers)) === count($headers) && count(array_diff($required, $headers)) === 0, 422, 'CSV requires: '.implode(', ', $required));
        $inputs = [];
        while (($row = fgetcsv($stream, escape: '')) !== false) {
            abort_unless(count($row) === count($headers) && count($inputs) < 250, 422, 'Import requires consistent columns and at most 250 rows.');
            $values = array_combine($headers, $row);
            foreach (['gross_minor', 'deductions_minor'] as $field) {
                abort_unless(preg_match('/^\d{1,13}$/D', $values[$field]), 422, 'Amounts must be integer minor units.');
            }
            $data = array_intersect_key($values, array_flip(['employee_user_id', 'period_start', 'period_end', 'payment_date', 'currency']));
            $data['earnings'] = [['description' => 'Imported earnings', 'amount_minor' => (int) $values['gross_minor']]];
            $data['deductions'] = [['description' => 'Imported deductions', 'amount_minor' => (int) $values['deductions_minor']]];
            $data['reimbursements'] = [];
            $inputs[] = validator($data, $this->rules())->validate();
        }
        fclose($stream);
        $runs = $this->records->transaction(fn () => array_map(fn (array $data) => $this->payroll->draft($data), $inputs));

        return response()->json(['data' => $runs], 201);
    }

    private function visibleRun(Request $request, string $id): array
    {
        $run = $this->records->get('pay_runs', $id);
        abort_if($request->user()->role === 'client', 403);
        if (! Access::can($request->user(), 'payroll.manage')) {
            abort_unless($run['employee_user_id'] === $request->user()->id && $run['status'] === 'published', 403);
        }

        return $run;
    }

    private function runInput(Request $request, bool $editing = false): array
    {
        $input = $request->validate($this->rules($editing));
        foreach (['earnings', 'deductions', 'reimbursements'] as $key) {
            $input[$key] = array_map(fn (array $line) => ['description' => $line['description'], 'amount_minor' => (int) $line['amount_minor']], $input[$key] ?? []);
        }

        return $input;
    }

    private function rules(bool $editing = false): array
    {
        $rules = ['earnings' => 'required|array|min:1|max:100', 'deductions' => 'present|array|max:100', 'reimbursements' => 'present|array|max:100', 'notes' => 'nullable|string|max:2000'];
        foreach (['earnings', 'deductions', 'reimbursements'] as $field) {
            $rules[$field.'.*.description'] = 'required|string|max:500';
            $rules[$field.'.*.amount_minor'] = 'required|integer|min:0|max:'.Money::MAX_MINOR;
        }
        if (! $editing) {
            $rules += ['employee_user_id' => 'required|string|max:100', 'period_start' => 'required|date_format:Y-m-d', 'period_end' => 'required|date_format:Y-m-d|after_or_equal:period_start', 'payment_date' => 'required|date_format:Y-m-d|after_or_equal:period_start', 'currency' => 'required|in:USD,CAD', 'amends_run_id' => 'nullable|string|max:100'];
        }

        return $rules;
    }
}

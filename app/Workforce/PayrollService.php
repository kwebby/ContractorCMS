<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace App\Workforce;

use App\Contracts\RecordRepository;
use App\Finance\Money;
use App\Support\Outbox;
use App\Support\Settings;
use Illuminate\Validation\ValidationException;

class PayrollService
{
    public function __construct(private RecordRepository $records) {}

    public function compensation(array $input): array
    {
        $employee = $this->records->get('users', $input['employee_user_id']);
        $this->ensure(($employee['role'] ?? 'client') !== 'client', 'employee_user_id', 'Compensation requires a staff account.');

        return $this->records->transaction(function () use ($input) {
            $this->records->lock('compensation:'.$input['employee_user_id']);
            foreach ($this->records->all('compensation', ['employee_user_id' => $input['employee_user_id']]) as $existing) {
                $overlap = $input['effective_from'] <= ($existing['effective_to'] ?? '9999-12-31') && $existing['effective_from'] <= ($input['effective_to'] ?? '9999-12-31');
                $this->ensure(! $overlap, 'effective_from', 'Compensation periods cannot overlap; end the earlier period before adding its replacement.');
            }

            return $this->records->create('compensation', array_merge($input, ['created_by' => auth()->id()]));
        });
    }

    public function draft(array $input): array
    {
        $employee = $this->records->get('users', $input['employee_user_id']);
        $this->ensure(($employee['role'] ?? 'client') !== 'client', 'employee_user_id', 'Payslips require a staff account.');

        return $this->records->transaction(function () use ($input) {
            $this->records->lock('payroll:'.$input['employee_user_id']);
            if (! empty($input['amends_run_id'])) {
                $original = $this->records->get('pay_runs', $input['amends_run_id']);
                $this->ensure($original['status'] === 'published' && $original['employee_user_id'] === $input['employee_user_id'] && $original['period_start'] === $input['period_start'] && $original['period_end'] === $input['period_end'] && $original['currency'] === $input['currency'], 'amends_run_id', 'Amendment must replace a published slip for this employee, period, and currency.');
                $this->ensure(empty($original['superseded_by']), 'amends_run_id', 'Amend the latest published version.');
            }
            foreach ($this->records->all('pay_runs', ['employee_user_id' => $input['employee_user_id']]) as $run) {
                if ($run['period_start'] === $input['period_start'] && $run['period_end'] === $input['period_end'] && empty($run['superseded_by'])) {
                    $this->ensure(($input['amends_run_id'] ?? '') === $run['id'], 'period_start', 'A pay run already exists for this period. Create an amendment to its published version.');
                }
            }
            $calculated = $this->calculate($input);

            return $this->records->create('pay_runs', array_merge($input, $calculated, ['status' => 'draft', 'created_by' => auth()->id()]));
        });
    }

    public function revise(string $id, array $input, int $version): array
    {
        return $this->records->transaction(function () use ($id, $input, $version) {
            $this->records->lock('pay-run:'.$id);
            $run = $this->records->get('pay_runs', $id);
            $this->ensure($run['status'] === 'draft', 'status', 'Only draft pay runs can be edited.');
            $updated = array_merge($run, $input);

            return $this->records->update('pay_runs', $id, array_merge($input, $this->calculate($updated)), $version);
        });
    }

    public function transition(string $id, string $state): array
    {
        return $this->records->transaction(function () use ($id, $state) {
            $run = $this->records->get('pay_runs', $id);
            $this->records->lock('payroll:'.$run['employee_user_id']);
            $run = $this->records->get('pay_runs', $id);
            if ($run['status'] === $state) {
                return $run;
            }
            $previous = ['review' => 'draft', 'approved' => 'review', 'published' => 'approved'][$state] ?? '';
            $this->ensure($run['status'] === $previous, 'status', 'Pay runs must pass draft, review, approval, and publication in order.');
            Outbox::audit('payroll.'.$state, ['run_id' => $id]);
            $changes = ['status' => $state, $state.'_at' => now()->toIso8601String(), $state.'_by' => auth()->id()];
            if ($state === 'published') {
                $business = Settings::get('business');
                $this->ensure(! empty($business['legal_name'] ?? $business['name'] ?? ''), 'business', 'Set the employer business details before publication.');
                $employee = $this->records->get('users', $run['employee_user_id']);
                $ytdGross = $run['gross_minor'];
                $ytdNet = $run['net_minor'];
                foreach ($this->records->all('pay_runs', ['employee_user_id' => $run['employee_user_id']]) as $other) {
                    if ($other['status'] === 'published' && empty($other['superseded_by']) && $other['id'] !== ($run['amends_run_id'] ?? '') && substr($other['payment_date'], 0, 4) === substr($run['payment_date'], 0, 4) && $other['payment_date'] <= $run['payment_date'] && $other['currency'] === $run['currency']) {
                        $ytdGross += $other['gross_minor'];
                        $ytdNet += $other['net_minor'];
                    }
                }
                $this->ensure($ytdGross <= Money::MAX_MINOR && $ytdNet <= Money::MAX_MINOR, 'earnings', 'Year-to-date values exceed the supported amount limit.');
                $number = 'PAY-'.str_pad((string) $this->records->sequence('payslip-number'), 6, '0', STR_PAD_LEFT);
                $snapshot = array_merge($run, ['number' => $number, 'business' => $business, 'employee' => ['name' => $employee['name'], 'email' => $employee['email']], 'ytd_gross_minor' => $ytdGross, 'ytd_net_minor' => $ytdNet]);
                if (! empty($run['amends_run_id'])) {
                    $original = $this->records->get('pay_runs', $run['amends_run_id']);
                    $this->ensure(empty($original['superseded_by']), 'amends_run_id', 'Another amendment has already superseded this slip.');
                    $snapshot['amends_number'] = $original['number'];
                    $this->records->update('pay_runs', $original['id'], ['superseded_by' => $id]);
                }
                $changes = array_merge($changes, ['number' => $number, 'snapshot' => $snapshot, 'pdf_ref' => 'documents/payslips/'.$id.'.pdf']);
                Outbox::audit('payslip.published', ['run_id' => $id, 'employee_user_id' => $run['employee_user_id']]);
                Outbox::push('payslip.published', ['run_id' => $id, 'employee_user_id' => $run['employee_user_id']], 'payslip-published:'.$id);
            }

            return $this->records->update('pay_runs', $id, $changes);
        });
    }

    public function timesheet(array $input, string $actor): array
    {
        $job = $this->records->get('jobs', $input['job_id']);
        $employeeId = $input['employee_user_id'] ?? $actor;
        $minutes = (int) $input['minutes'];
        $this->ensure($minutes > 0 && $minutes <= 1440, 'minutes', 'Time must be between 1 and 1440 minutes per entry.');
        if ($employeeId === $actor) {
            $user = $this->records->get('users', $actor);
            if (in_array($user['role'], ['technician', 'crew_lead'], true)) {
                $this->ensure(in_array($actor, $job['assigned_to'] ?? [], true), 'job_id', 'You can log time only against an assigned job.');
            }
        }

        return $this->records->create('timesheets', array_merge($input, ['employee_user_id' => $employeeId, 'status' => 'submitted', 'submitted_by' => $actor]));
    }

    private function calculate(array $input): array
    {
        $sum = function (string $key) use ($input): int {
            $total = 0;
            foreach ($input[$key] ?? [] as $line) {
                $amount = $line['amount_minor'];
                $this->ensure(is_int($amount) && $amount >= 0 && $amount <= Money::MAX_MINOR, $key, 'Amounts must be nonnegative integer minor units.');
                $total += $amount;
                $this->ensure($total <= Money::MAX_MINOR, $key, 'Pay run exceeds the supported amount limit.');
            }

            return $total;
        };
        $gross = $sum('earnings');
        $deductions = $sum('deductions');
        $reimbursements = $sum('reimbursements');
        $this->ensure($gross + $reimbursements <= Money::MAX_MINOR, 'earnings', 'Combined earnings and reimbursements exceed the supported amount limit.');
        $this->ensure($deductions <= $gross + $reimbursements, 'deductions', 'Deductions exceed earnings plus reimbursements.');

        return ['gross_minor' => $gross, 'deductions_minor' => $deductions, 'reimbursements_minor' => $reimbursements, 'net_minor' => $gross - $deductions + $reimbursements];
    }

    private function ensure(bool $condition, string $key, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages([$key => $message]);
        }
    }
}

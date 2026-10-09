<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace Tests\Feature;

use App\Contracts\RecordRepository;
use App\Identity\CmsUser;
use App\Support\Settings;
use App\Workforce\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PayrollWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private RecordRepository $records;

    private PayrollService $payroll;

    private array $employee;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cms.data_provider' => 'postgres', 'cms.mfa_required' => false]);
        if (! Route::has('payroll.pdf')) {
            Route::middleware('web')->group(base_path('routes/finance.php'));
        }
        $this->records = app(RecordRepository::class);
        $this->payroll = app(PayrollService::class);
        $this->employee = $this->records->create('users', ['name' => 'Taylor Tech', 'email' => 'taylor@example.test', 'role' => 'technician']);
        Settings::put('business', ['legal_name' => 'Sample Plumbing LLC']);
        $this->actingAs(new CmsUser(['id' => 'owner', 'name' => 'Owner', 'email' => 'owner@example.test', 'role' => 'owner']));
    }

    private function input(): array
    {
        return ['employee_user_id' => $this->employee['id'], 'period_start' => '2026-09-01', 'period_end' => '2026-09-15', 'payment_date' => '2026-09-20', 'currency' => 'USD', 'earnings' => [['description' => 'Regular earnings', 'amount_minor' => 200000]], 'deductions' => [['description' => 'Imported tax withholding', 'amount_minor' => 35000]], 'reimbursements' => [['description' => 'Mileage', 'amount_minor' => 5000]]];
    }

    private function publish(array $input): array
    {
        $run = $this->payroll->draft($input);
        $this->payroll->transition($run['id'], 'review');
        $this->payroll->transition($run['id'], 'approved');

        return $this->payroll->transition($run['id'], 'published');
    }

    public function test_publication_requires_review_and_approval(): void
    {
        $run = $this->payroll->draft($this->input());
        $this->postJson('/api/v1/payroll/runs/'.$run['id'].'/publish')->assertUnprocessable();
        $this->postJson('/api/v1/payroll/runs/'.$run['id'].'/review')->assertOk();
        $this->postJson('/api/v1/payroll/runs/'.$run['id'].'/approve')->assertStatus(423);
        $this->withSession(['auth.password_confirmed_at' => now()->timestamp])->postJson('/api/v1/payroll/runs/'.$run['id'].'/approve')->assertOk();
        $this->postJson('/api/v1/payroll/runs/'.$run['id'].'/publish')->assertOk()->assertJsonPath('data.net_minor', 170000);
    }

    public function test_published_slips_are_private_and_immutable(): void
    {
        Storage::fake('local');
        $run = $this->publish($this->input());
        $this->actingAs(new CmsUser($this->employee));
        $this->getJson('/api/v1/payroll/runs')->assertOk()->assertJsonCount(1, 'data');
        $this->get('/api/v1/payroll/runs/'.$run['id'].'/pdf')->assertOk()->assertDownload($run['number'].'.pdf');
        $this->actingAs(new CmsUser(['id' => 'other-tech', 'role' => 'technician']));
        $this->getJson('/api/v1/payroll/runs/'.$run['id'])->assertForbidden();
        $this->get('/api/v1/payroll/runs/'.$run['id'].'/pdf')->assertForbidden();
        $this->get('/api/v1/payroll/export')->assertForbidden();
        $this->expectException(ValidationException::class);
        $this->payroll->revise($run['id'], ['earnings' => [['description' => 'Changed', 'amount_minor' => 1]]], $run['version']);
    }

    public function test_amendment_preserves_previous_snapshot_and_replaces_ytd(): void
    {
        $first = $this->publish($this->input());
        $input = $this->input();
        $input['amends_run_id'] = $first['id'];
        $input['earnings'][0]['amount_minor'] = 210000;
        $amended = $this->publish($input);
        $previous = $this->records->get('pay_runs', $first['id']);
        $this->assertSame(200000, $previous['snapshot']['gross_minor']);
        $this->assertSame($amended['id'], $previous['superseded_by']);
        $this->assertSame(210000, $amended['snapshot']['ytd_gross_minor']);
        $this->assertSame(180000, $amended['snapshot']['ytd_net_minor']);
    }

    public function test_overlapping_compensation_and_duplicate_pay_runs_are_rejected(): void
    {
        $this->payroll->compensation(['employee_user_id' => $this->employee['id'], 'type' => 'hourly', 'rate_minor' => 2500, 'currency' => 'USD', 'frequency' => 'hour', 'effective_from' => '2026-01-01']);
        $this->postJson('/api/v1/payroll/compensation', ['employee_user_id' => $this->employee['id'], 'type' => 'hourly', 'rate_minor' => 2700, 'currency' => 'USD', 'frequency' => 'hour', 'effective_from' => '2026-06-01'])->assertUnprocessable();
        $this->payroll->draft($this->input());
        $this->expectException(ValidationException::class);
        $this->payroll->draft($this->input());
    }

    public function test_clients_cannot_access_workforce_and_unassigned_technicians_cannot_log_time(): void
    {
        $job = $this->records->create('jobs', ['title' => 'Other job', 'assigned_to' => []]);
        $this->actingAs(new CmsUser($this->employee));
        $this->postJson('/api/v1/payroll/timesheets', ['job_id' => $job['id'], 'worked_on' => '2026-09-01', 'minutes' => 60, 'description' => 'Unassigned work'])->assertUnprocessable();
        $this->actingAs(new CmsUser(['id' => 'client', 'role' => 'client']));
        $this->getJson('/api/v1/payroll/runs')->assertForbidden();
        $this->getJson('/api/v1/payroll/compensation')->assertForbidden();
        $this->postJson('/api/v1/payroll/runs', $this->input())->assertForbidden();
    }

    public function test_editing_compensation_end_date_cannot_overlap_a_later_rate(): void
    {
        $first = $this->payroll->compensation(['employee_user_id' => $this->employee['id'], 'type' => 'hourly', 'rate_minor' => 2500, 'currency' => 'USD', 'frequency' => 'hour', 'effective_from' => '2026-01-01', 'effective_to' => '2026-05-31']);
        $this->payroll->compensation(['employee_user_id' => $this->employee['id'], 'type' => 'hourly', 'rate_minor' => 2700, 'currency' => 'USD', 'frequency' => 'hour', 'effective_from' => '2026-06-01']);
        $this->putJson('/api/v1/payroll/compensation/'.$first['id'].'/end', ['version' => $first['version'], 'effective_to' => '2026-07-01'])->assertUnprocessable();
        $this->assertSame('2026-05-31', $this->records->get('compensation', $first['id'])['effective_to']);
    }
}

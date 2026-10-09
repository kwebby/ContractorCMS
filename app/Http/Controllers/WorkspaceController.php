<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace App\Http\Controllers;

use App\Contracts\RecordRepository;
use App\Finance\FinanceService;
use App\Publishing\WebsiteSettings;
use App\Support\Access;
use App\Support\Settings;
use Illuminate\Http\Request;
use Inertia\Inertia;

class WorkspaceController extends Controller
{
    private const SETTINGS_PAGES = ['business', 'mail', 'payments', 'invoice-design', 'ai', 'security', 'health', 'email-templates'];

    /**
     * Audit action prefixes that reveal finance, payroll, access or customer-portal activity.
     *
     * @var array<string, string>
     */
    private const AUDIT_PERMISSIONS = ['payroll.' => 'payroll.manage', 'payslip.' => 'payroll.manage', 'payment.' => 'finance.manage', 'invoice.' => 'finance.manage', 'deposit.' => 'finance.manage', 'team.' => 'team.manage', 'mfa.' => 'team.manage', 'settings.' => 'settings.manage', 'email_template.' => 'settings.manage', 'client.' => 'crm.manage'];

    public function __construct(private RecordRepository $records) {}

    private function workspaceBrand(): array
    {
        $business = Settings::get('business');
        $public = app(WebsiteSettings::class)->business();

        return ['name' => $business['name'] ?? 'Your business', 'currency' => $business['currency'] ?? 'USD', 'city' => $public['city'] ?? '', 'region' => $public['region'] ?? ''];
    }

    public function page(Request $request, ?string $section = null): mixed
    {
        if ($section === 'settings' && $request->has('tab')) {
            $group = $request->string('tab')->toString();
            abort_unless(in_array($group, self::SETTINGS_PAGES, true), 404);

            return redirect()->route('workspace.settings', ['settingsPage' => $group]);
        }

        return Inertia::render('Workspace', ['section' => $section ?? 'dashboard', 'currentPath' => $request->path(), 'business' => $this->workspaceBrand()]);
    }

    public function settingsPage(Request $request, string $settingsPage): mixed
    {
        Access::authorize('settings.manage');
        abort_unless(in_array($settingsPage, self::SETTINGS_PAGES, true), 404);

        return Inertia::render('Workspace', ['section' => 'settings', 'settingsPage' => $settingsPage, 'currentPath' => $request->path(), 'business' => $this->workspaceBrand()]);
    }

    public function themeUpload(Request $request): mixed
    {
        Access::authorize('publishing.manage');

        return Inertia::render('Workspace', ['section' => 'themes', 'themePage' => 'upload', 'currentPath' => $request->path(), 'business' => $this->workspaceBrand()]);
    }

    public function themeSettings(Request $request, string $id): mixed
    {
        Access::authorize('publishing.manage');
        $this->records->get('themes', $id);

        return Inertia::render('Workspace', ['section' => 'themes', 'themePage' => 'settings', 'themeId' => $id, 'currentPath' => $request->path(), 'business' => $this->workspaceBrand()]);
    }

    public function websiteSeo(Request $request): mixed
    {
        Access::authorize('publishing.manage');

        return Inertia::render('Workspace', ['section' => 'pages', 'publishingPage' => 'seo', 'currentPath' => $request->path(), 'business' => $this->workspaceBrand()]);
    }

    public function websitePage(Request $request): mixed
    {
        Access::authorize('publishing.manage');
        $page = $request->route('websitePage');
        abort_unless(in_array($page, ['homepage', 'appearance', 'navigation', 'local-seo', 'business-profile', 'locations', 'location', 'citations', 'page-plan'], true), 404);

        return Inertia::render('Workspace', ['section' => 'pages', 'websitePage' => $page, 'locationId' => $request->route('locationId'), 'currentPath' => $request->path(), 'business' => $this->workspaceBrand()]);
    }

    public function dashboard(Request $request): mixed
    {
        $user = $request->user();
        if ($user->role === 'client' && ! $user->email_verified_at) {
            return response()->json(['data' => ['metrics' => ['open_leads' => 0, 'active_jobs' => 0, 'outstanding_minor' => 0, 'collected_minor' => 0, 'currency' => Settings::get('business')['currency'] ?? 'USD'], 'jobs' => [], 'leads' => [], 'tasks' => [], 'activity' => [], 'notifications' => []]]);
        }
        $filter = fn (array $row): bool => $user->role === 'client' ? ($user->customer_id && ($row['customer_id'] ?? null) === $user->customer_id) : ($user->role === 'technician' ? in_array($user->id, $row['assigned_to'] ?? [], true) : true);
        $operations = Access::can($user, 'operations.manage') || Access::can($user, 'operations.view') || in_array($user->role, ['client', 'technician']);
        $jobs = $operations ? array_values(array_filter($this->records->all('jobs'), $filter)) : [];
        $finance = Access::can($user, 'finance.manage') || $user->role === 'client';
        $invoices = $finance ? array_values(array_filter($this->records->all('invoices'), $filter)) : [];
        $leads = (Access::can($user, 'crm.manage') || Access::can($user, 'crm.view')) ? $this->records->all('leads') : [];
        $currency = Settings::get('business')['currency'] ?? 'USD';
        $totals = [];
        foreach ($invoices as $invoice) {
            if (in_array($invoice['status'] ?? '', ['issued', 'uncollectible'])) {
                $invoice = app(FinanceService::class)->balance($invoice);
                $invoiceCurrency = $invoice['currency'] ?? $currency;
                $totals[$invoiceCurrency] ??= ['currency' => $invoiceCurrency, 'outstanding_minor' => 0, 'collected_minor' => 0];
                $totals[$invoiceCurrency]['outstanding_minor'] += (int) ($invoice['balance_minor'] ?? $invoice['total_minor'] ?? 0);
                $totals[$invoiceCurrency]['collected_minor'] += (int) ($invoice['paid_minor'] ?? 0);
            }
        }
        $primary = $totals[$currency] ?? ['outstanding_minor' => 0, 'collected_minor' => 0];
        unset($totals[$currency]);
        $canReadTasks = $user->role !== 'client' && ($operations || Access::can($user, 'crm.manage') || Access::can($user, 'crm.view'));
        $tasks = $canReadTasks ? array_values(array_filter($this->records->all('tasks'), fn (array $r): bool => $user->role !== 'technician' || in_array($user->id, $r['assigned_to'] ?? [], true))) : [];
        $activity = Access::can($user, 'reports.view') ? array_slice(array_values(array_filter(array_reverse($this->records->all('audit')), fn (array $event): bool => $this->canSeeAudit($user, $event))), 0, 10) : [];

        return response()->json(['data' => ['metrics' => ['open_leads' => count(array_filter($leads, fn ($r) => ! in_array($r['stage'] ?? 'new', ['won', 'lost']))), 'active_jobs' => count(array_filter($jobs, fn ($r) => ! in_array($r['status'] ?? 'scheduled', ['completed', 'cancelled']))), 'outstanding_minor' => $primary['outstanding_minor'], 'collected_minor' => $primary['collected_minor'], 'currency' => $currency, 'other_currencies' => array_values($totals)], 'jobs' => array_map(fn (array $record): array => Access::publicRecord($user, 'jobs', $record), $jobs), 'leads' => $leads, 'tasks' => $tasks, 'activity' => $activity, 'notifications' => $this->records->all('notifications', ['user_id' => $user->id])]]);
    }

    public function reports(Request $request): mixed
    {
        Access::authorize('reports.view');
        $user = $request->user();
        $leadSources = [];
        foreach ($this->records->all('leads') as $lead) {
            $key = $lead['source'] ?? 'unknown';
            $leadSources[$key] = ($leadSources[$key] ?? 0) + 1;
        }
        $canReadJobs = Access::can($user, 'operations.manage') || Access::can($user, 'operations.view') || Access::can($user, 'finance.manage');
        $jobs = $canReadJobs ? array_map(fn (array $record): array => Access::publicRecord($user, 'jobs', $record), $this->records->all('jobs')) : [];
        $invoices = Access::can($user, 'finance.manage') ? $this->records->all('invoices') : [];

        return response()->json(['data' => ['lead_sources' => $leadSources, 'jobs' => $jobs, 'invoices' => $invoices, 'generated_at' => now()->toISOString()]]);
    }

    private function canSeeAudit(object $user, array $event): bool
    {
        foreach (self::AUDIT_PERMISSIONS as $prefix => $permission) {
            if (str_starts_with((string) ($event['action'] ?? ''), $prefix)) {
                return Access::can($user, $permission);
            }
        }

        return true;
    }
}

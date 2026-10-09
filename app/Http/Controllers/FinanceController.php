<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace App\Http\Controllers;

use App\Contracts\RecordRepository;
use App\Finance\DocumentPdf;
use App\Finance\FinanceService;
use App\Finance\Money;
use App\Finance\PaymentGateway;
use App\Support\Access;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class FinanceController extends Controller
{
    public function __construct(private RecordRepository $records, private FinanceService $finance, private PaymentGateway $gateways) {}

    public function estimates(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->visible($request, 'estimates', 'estimates.manage')]);
    }

    public function estimate(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->record($request, 'estimates', $id, 'estimates.manage')]);
    }

    public function createEstimate(Request $request): JsonResponse
    {
        Access::authorize('estimates.manage');

        return response()->json(['data' => $this->finance->draft('estimates', $this->documentInput($request))], 201);
    }

    public function reviseEstimate(Request $request, string $id): JsonResponse
    {
        Access::authorize('estimates.manage');
        $input = $this->documentInput($request, true);
        $version = $request->validate(['version' => 'required|integer|min:1'])['version'];

        return response()->json(['data' => $this->finance->revise('estimates', $id, $input, $version)]);
    }

    public function sendEstimate(string $id): JsonResponse
    {
        Access::authorize('estimates.manage');

        return response()->json(['data' => $this->finance->sendEstimate($id)]);
    }

    public function acceptEstimate(Request $request, string $id): JsonResponse
    {
        $this->record($request, 'estimates', $id, 'estimates.manage');
        $version = $request->validate(['version' => 'required|integer|min:1'])['version'];

        return response()->json(['data' => $this->finance->acceptEstimate($id, $version, (string) $request->user()->id, $request->ip())]);
    }

    public function duplicateEstimate(string $id): JsonResponse
    {
        Access::authorize('estimates.manage');
        $original = $this->records->get('estimates', $id);
        $input = Arr::only($original, ['customer_id', 'title', 'currency', 'items', 'notes', 'service_address', 'po_number']);
        $draft = $this->finance->draft('estimates', $input + ['replaces_estimate_id' => $id]);

        return response()->json(['data' => $draft], 201);
    }

    public function invoices(Request $request): JsonResponse
    {
        return response()->json(['data' => array_map(fn (array $invoice) => $this->finance->balance($invoice), $this->visible($request, 'invoices', 'finance.manage'))]);
    }

    public function invoice(Request $request, string $id): JsonResponse
    {
        $invoice = $this->record($request, 'invoices', $id, 'finance.manage');

        return response()->json(['data' => array_merge($this->finance->balance($invoice), ['payments' => $this->records->all('payments', ['invoice_id' => $id]), 'credits' => $this->records->all('credit_notes', ['invoice_id' => $id]), 'refunds' => $this->records->all('refunds', ['invoice_id' => $id])])]);
    }

    public function createInvoice(Request $request): JsonResponse
    {
        Access::authorize('finance.manage');

        return response()->json(['data' => $this->finance->draft('invoices', $this->documentInput($request))], 201);
    }

    public function reviseInvoice(Request $request, string $id): JsonResponse
    {
        Access::authorize('finance.manage');
        $input = $this->documentInput($request, true);
        $version = $request->validate(['version' => 'required|integer|min:1'])['version'];

        return response()->json(['data' => $this->finance->revise('invoices', $id, $input, $version)]);
    }

    public function issueInvoice(string $id): JsonResponse
    {
        Access::authorize('finance.manage');

        return response()->json(['data' => $this->finance->issue($id)]);
    }

    public function invoiceState(Request $request, string $id): JsonResponse
    {
        Access::authorize('finance.manage');
        $input = $request->validate(['status' => 'required|in:void,uncollectible', 'reason' => 'required|string|min:3|max:2000']);

        return response()->json(['data' => $this->finance->state($id, $input['status'], $input['reason'])]);
    }

    public function invoicePdf(Request $request, string $id, DocumentPdf $pdf): BinaryFileResponse
    {
        $invoice = $this->record($request, 'invoices', $id, 'finance.manage');

        return response()->download($pdf->invoice($invoice), ($invoice['number'] ?? 'invoice').'.pdf', ['Cache-Control' => 'private, no-store', 'X-Robots-Tag' => 'noindex, nofollow']);
    }

    public function payment(Request $request, string $id): JsonResponse
    {
        Access::authorize('finance.manage');
        $input = $request->validate(['amount_minor' => 'required|integer|min:1|max:'.Money::MAX_MINOR, 'method' => 'required|in:cash,cheque,bank_transfer', 'reference' => 'required|string|max:200', 'idempotency_key' => 'required|string|min:8|max:150']);
        $payment = $this->finance->payment($id, (int) $input['amount_minor'], $input['method'], $input['reference'], $input['idempotency_key']);

        return response()->json(['data' => $payment]);
    }

    public function credit(Request $request, string $id): JsonResponse
    {
        Access::authorize('finance.manage');
        $input = $request->validate(['amount_minor' => 'required|integer|min:1|max:'.Money::MAX_MINOR, 'reason' => 'required|string|min:3|max:2000', 'idempotency_key' => 'required|string|min:8|max:150']);

        return response()->json(['data' => $this->finance->credit($id, (int) $input['amount_minor'], $input['reason'], $input['idempotency_key'])]);
    }

    public function refund(Request $request, string $id): JsonResponse
    {
        Access::authorize('finance.manage');
        $this->recentPassword($request);
        $input = $request->validate(['amount_minor' => 'required|integer|min:1|max:'.Money::MAX_MINOR, 'reason' => 'required|string|min:3|max:2000', 'idempotency_key' => 'required|string|min:8|max:150']);

        return response()->json(['data' => $this->gateways->refund($this->records->get('payments', $id), (int) $input['amount_minor'], $input['reason'], $input['idempotency_key'])]);
    }

    public function checkout(Request $request, string $id): JsonResponse
    {
        $this->record($request, 'invoices', $id, 'finance.manage');
        $provider = $request->validate(['provider' => 'required|in:stripe,paypal'])['provider'];
        $attempt = $this->gateways->checkout($id, $provider);

        return response()->json(['data' => ['url' => $attempt['url'], 'attempt_id' => $attempt['id'], 'provider' => $provider]]);
    }

    public function reconcile(string $id): JsonResponse
    {
        Access::authorize('finance.manage');

        return response()->json(['data' => $this->gateways->reconcile($id)]);
    }

    public function receipt(Request $request, string $id, DocumentPdf $pdf): BinaryFileResponse
    {
        $payment = $this->records->get('payments', $id);
        $this->record($request, 'invoices', $payment['invoice_id'], 'finance.manage');

        return response()->download($pdf->receipt($payment), ($payment['snapshot']['receipt_number'] ?? 'receipt').'.pdf', ['Cache-Control' => 'private, no-store', 'X-Robots-Tag' => 'noindex, nofollow']);
    }

    public function deposits(Request $request): JsonResponse
    {
        $records = $this->visible($request, 'customer_deposits', 'finance.manage');

        return response()->json(['data' => array_map(fn (array $deposit) => $this->finance->depositBalance($deposit), $records)]);
    }

    public function createDeposit(Request $request): JsonResponse
    {
        Access::authorize('finance.manage');
        $input = $request->validate(['customer_id' => 'required|string|max:100', 'currency' => 'required|in:USD,CAD', 'amount_minor' => 'required|integer|min:1|max:'.Money::MAX_MINOR, 'method' => 'required|in:cash,cheque,bank_transfer', 'reference' => 'required|string|max:200', 'idempotency_key' => 'required|string|min:8|max:150']);

        return response()->json(['data' => $this->finance->deposit($input)]);
    }

    public function allocateDeposit(Request $request, string $id): JsonResponse
    {
        Access::authorize('finance.manage');
        $input = $request->validate(['invoice_id' => 'required|string|max:100', 'amount_minor' => 'required|integer|min:1|max:'.Money::MAX_MINOR, 'idempotency_key' => 'required|string|min:8|max:150']);

        return response()->json(['data' => $this->finance->allocateDeposit($id, $input['invoice_id'], (int) $input['amount_minor'], $input['idempotency_key'])]);
    }

    public function refundDeposit(Request $request, string $id): JsonResponse
    {
        Access::authorize('finance.manage');
        $this->recentPassword($request);
        $input = $request->validate(['amount_minor' => 'required|integer|min:1|max:'.Money::MAX_MINOR, 'reason' => 'required|string|min:3|max:2000', 'idempotency_key' => 'required|string|min:8|max:150']);

        return response()->json(['data' => $this->finance->refundDeposit($id, (int) $input['amount_minor'], $input['reason'], $input['idempotency_key'])]);
    }

    public function transferCredit(Request $request, string $id): JsonResponse
    {
        Access::authorize('finance.manage');
        $input = $request->validate(['invoice_id' => 'required|string|max:100', 'amount_minor' => 'required|integer|min:1|max:'.Money::MAX_MINOR, 'idempotency_key' => 'required|string|min:8|max:150']);

        return response()->json(['data' => $this->finance->transferCredit($id, $input['invoice_id'], (int) $input['amount_minor'], $input['idempotency_key'])]);
    }

    public function providers(): JsonResponse
    {
        return response()->json(['data' => $this->gateways->availability()]);
    }

    public function stripeWebhook(Request $request): JsonResponse
    {
        $this->gateways->stripeWebhook($request);

        return response()->json(['received' => true]);
    }

    public function paypalWebhook(Request $request): JsonResponse
    {
        $this->gateways->paypalWebhook($request);

        return response()->json(['received' => true]);
    }

    public function design(): JsonResponse
    {
        Access::authorize('finance.manage');

        return response()->json(['data' => $this->finance->design()]);
    }

    public function saveDesign(Request $request): JsonResponse
    {
        Access::authorize('finance.manage');
        $input = $request->validate(['accent_color' => ['required', 'regex:/^#[a-fA-F0-9]{6}$/'], 'font' => 'required|in:DejaVu Sans,DejaVu Serif,Helvetica', 'paper' => 'required|in:letter,a4', 'terms' => 'nullable|string|max:5000', 'footer' => 'nullable|string|max:2000', 'payment_instructions' => 'nullable|string|max:5000', 'show_quantity' => 'required|boolean', 'show_tax' => 'required|boolean']);
        Settings::put('invoice_design', $input);

        return response()->json(['data' => $this->finance->design()]);
    }

    private function documentInput(Request $request, bool $editing = false): array
    {
        $rules = ['title' => 'required|string|max:200', 'items' => 'required|array|min:1|max:100', 'items.*.description' => 'required|string|max:2000', 'items.*.quantity' => ['required', 'string', 'regex:/^\d{1,5}(\.\d{1,3})?$/'], 'items.*.unit_price_minor' => 'required|integer|min:0|max:1000000000', 'items.*.tax_basis_points' => 'sometimes|integer|min:0|max:10000', 'items.*.discount_minor' => 'sometimes|integer|min:0|max:'.Money::MAX_MINOR, 'notes' => 'nullable|string|max:10000', 'due_date' => 'nullable|date_format:Y-m-d', 'po_number' => 'nullable|string|max:100', 'service_address' => 'nullable|string|max:1000'];
        if (! $editing) {
            $rules += ['customer_id' => 'required|string|max:100', 'currency' => 'required|in:USD,CAD', 'job_id' => 'nullable|string|max:100'];
        }

        return $request->validate($rules);
    }

    private function visible(Request $request, string $collection, string $permission): array
    {
        if ($request->user()->role === 'client') {
            abort_unless($request->user()->email_verified_at && $request->user()->customer_id, 403);

            return array_values(array_filter($this->records->all($collection, ['customer_id' => $request->user()->customer_id]), fn (array $record) => $record['status'] !== 'draft'));
        }
        Access::authorize($permission);

        return $this->records->all($collection);
    }

    private function record(Request $request, string $collection, string $id, string $permission): array
    {
        $record = $this->records->get($collection, $id);
        Access::authorizeRecord($request->user(), $record, $permission);
        if ($request->user()->role === 'client') {
            abort_if($record['status'] === 'draft', 403);
        }

        return $record;
    }

    private function recentPassword(Request $request): void
    {
        abort_unless((int) $request->session()->get('auth.password_confirmed_at', 0) >= now()->subMinutes(15)->timestamp, 423, 'Confirm your password before making this change.');
    }
}

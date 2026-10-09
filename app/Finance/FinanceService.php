<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace App\Finance;

use App\Contracts\RecordRepository;
use App\Support\Outbox;
use App\Support\Settings;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class FinanceService
{
    public function __construct(private RecordRepository $records) {}

    public function calculate(array $items, string $currency): array
    {
        $subtotal = $tax = $discount = 0;
        $lines = [];
        foreach ($items as $item) {
            $unit = (int) $item['unit_price_minor'];
            $quantity = (string) ($item['quantity'] ?? '1');
            $rate = (int) ($item['tax_basis_points'] ?? 0);
            $lineDiscount = (int) ($item['discount_minor'] ?? 0);
            try {
                new Money($unit, $currency);
                $milli = Money::quantity($quantity);
            } catch (\InvalidArgumentException $exception) {
                throw ValidationException::withMessages(['items' => $exception->getMessage()]);
            }
            $this->ensure($unit >= 0 && $unit <= 1000000000 && $rate >= 0 && $rate <= 10000 && $lineDiscount >= 0, 'items', 'Invalid price, discount, or tax rate.');
            $gross = Money::roundRatio($unit * $milli, 1000);
            $this->ensure($lineDiscount <= $gross, 'items', 'A line discount cannot exceed its value.');
            $lineNet = $gross - $lineDiscount;
            $lineTax = Money::roundRatio($lineNet * $rate, 10000);
            $lines[] = ['description' => $item['description'], 'quantity' => $quantity, 'unit_price_minor' => $unit, 'tax_basis_points' => $rate, 'discount_minor' => $lineDiscount, 'subtotal_minor' => $lineNet, 'tax_minor' => $lineTax, 'total_minor' => $lineNet + $lineTax];
            $subtotal += $lineNet;
            $tax += $lineTax;
            $discount += $lineDiscount;
        }
        $this->ensure($subtotal + $tax <= Money::MAX_MINOR, 'items', 'Document exceeds the supported amount limit.');

        return ['items' => $lines, 'subtotal_minor' => $subtotal, 'tax_minor' => $tax, 'discount_minor' => $discount, 'total_minor' => $subtotal + $tax];
    }

    public function draft(string $collection, array $input): array
    {
        $customer = $this->records->get('customers', $input['customer_id']);
        $currency = $input['currency'] ?? 'USD';
        $this->ensure(in_array($currency, ['USD', 'CAD'], true), 'currency', 'Choose USD or CAD.');
        if (! empty($input['job_id'])) {
            $job = $this->records->get('jobs', $input['job_id']);
            $this->ensure(($job['customer_id'] ?? null) === $customer['id'], 'job_id', 'Job must belong to the selected customer.');
        }

        return $this->records->create($collection, array_merge($input, $this->calculate($input['items'], $currency), [
            'customer_id' => $customer['id'], 'customer_name' => $customer['name'], 'currency' => $currency,
            'status' => 'draft', 'revision' => 1, 'created_by' => auth()->id(),
        ]));
    }

    public function revise(string $collection, string $id, array $input, int $version): array
    {
        return $this->records->transaction(function () use ($collection, $id, $input, $version) {
            $this->records->lock($collection.':'.$id);
            $record = $this->records->get($collection, $id);
            $this->ensure($record['status'] === 'draft', 'status', 'Only drafts can be edited. Duplicate a sent estimate or issue a credit for an issued invoice.');
            $calculated = $this->calculate($input['items'], $record['currency']);

            return $this->records->update($collection, $id, array_merge($input, $calculated, ['revision' => $record['revision'] + 1]), $version);
        });
    }

    public function sendEstimate(string $id): array
    {
        return $this->records->transaction(function () use ($id) {
            $this->records->lock('estimates:'.$id);
            $estimate = $this->records->get('estimates', $id);
            $this->ensure($estimate['status'] === 'draft', 'status', 'Only draft estimates can be sent.');
            $estimate = $this->records->update('estimates', $id, ['status' => 'sent', 'sent_at' => now()->toIso8601String(), 'snapshot' => $estimate]);
            Outbox::push('estimate.sent', ['estimate_id' => $id, 'customer_id' => $estimate['customer_id']], 'estimate-sent:'.$id.':'.$estimate['revision']);

            return $estimate;
        });
    }

    public function acceptEstimate(string $id, int $version, string $actor, string $ip): array
    {
        return $this->records->transaction(function () use ($id, $version, $actor, $ip) {
            $this->records->lock('estimates:'.$id);
            $estimate = $this->records->get('estimates', $id);
            if ($estimate['status'] === 'accepted' && (int) ($estimate['accepted_version'] ?? 0) === $version) {
                return $estimate;
            }
            $this->ensure($estimate['status'] === 'sent', 'status', 'This estimate is not awaiting approval.');
            $this->ensure((int) $estimate['version'] === $version, 'version', 'The estimate changed. Review its current revision before accepting.');
            $job = $this->records->create('jobs', ['title' => $estimate['title'], 'customer_id' => $estimate['customer_id'], 'customer_name' => $estimate['customer_name'], 'estimate_id' => $id, 'scope' => $estimate['items'], 'status' => 'approved', 'assigned_to' => [], 'currency' => $estimate['currency'], 'estimated_total_minor' => $estimate['total_minor'], 'notes' => $estimate['notes'] ?? '']);
            $estimate = $this->records->update('estimates', $id, ['status' => 'accepted', 'accepted_version' => $version, 'accepted_by' => $actor, 'accepted_ip' => $ip, 'accepted_at' => now()->toIso8601String(), 'job_id' => $job['id']], $version);
            Outbox::push('estimate.accepted', ['estimate_id' => $id, 'job_id' => $job['id'], 'customer_id' => $estimate['customer_id']], 'estimate-accepted:'.$id);

            return $estimate;
        });
    }

    public function issue(string $id): array
    {
        return $this->records->transaction(function () use ($id) {
            $this->records->lock('invoices:'.$id);
            $invoice = $this->records->get('invoices', $id);
            if ($invoice['status'] === 'issued') {
                return $this->balance($invoice);
            }
            $this->ensure($invoice['status'] === 'draft', 'status', 'Only draft invoices can be issued.');
            $business = Settings::get('business');
            $this->ensure(! empty($business['legal_name'] ?? $business['name'] ?? null), 'business', 'Set the legal business name before issuing an invoice.');
            $number = 'INV-'.str_pad((string) $this->records->sequence('invoice-number'), 6, '0', STR_PAD_LEFT);
            $issuedAt = now()->toIso8601String();
            $snapshot = array_merge($invoice, ['number' => $number, 'issued_at' => $issuedAt, 'business' => $business, 'customer' => $this->publicCustomer($invoice['customer_id']), 'design' => $this->design()]);
            $invoice = $this->records->update('invoices', $id, ['status' => 'issued', 'number' => $number, 'issued_at' => $issuedAt, 'snapshot' => $snapshot, 'pdf_ref' => 'documents/invoices/'.$id.'.pdf']);
            Outbox::audit('invoice.issued', ['invoice_id' => $id, 'number' => $number]);
            Outbox::push('invoice.issued', ['invoice_id' => $id, 'customer_id' => $invoice['customer_id']], 'invoice-issued:'.$id);

            return $this->balance($invoice);
        });
    }

    public function balance(array $invoice): array
    {
        $paid = $refunded = $reversed = $credit = $disputed = 0;
        foreach ($this->records->all('payments', ['invoice_id' => $invoice['id']]) as $payment) {
            if (($payment['status'] ?? null) === 'settled') {
                $paid += $payment['amount_minor'];
            }
        }
        foreach ($this->records->all('refunds', ['invoice_id' => $invoice['id']]) as $refund) {
            if (($refund['status'] ?? null) === 'settled') {
                $refunded += $refund['amount_minor'];
            }
        }
        foreach ($this->records->all('reversals', ['invoice_id' => $invoice['id']]) as $reversal) {
            $reversed += $reversal['amount_minor'];
        }
        foreach ($this->records->all('credit_notes', ['invoice_id' => $invoice['id']]) as $note) {
            $credit += $note['amount_minor'];
        }
        foreach ($this->records->all('disputes', ['invoice_id' => $invoice['id']]) as $dispute) {
            if (($dispute['status'] ?? null) === 'open') {
                $disputed += $dispute['amount_minor'];
            }
        }
        $transferred = array_sum(array_column($this->records->all('credit_transfers', ['source_invoice_id' => $invoice['id']]), 'amount_minor'));
        $net = $paid - $refunded - $reversed - $transferred;
        $due = max(0, $invoice['total_minor'] - $credit);
        $balance = max(0, $due - $net);

        return array_merge($invoice, ['paid_minor' => $net, 'transferred_credit_minor' => $transferred, 'refunded_minor' => $refunded, 'credit_minor' => $credit, 'disputed_minor' => $disputed, 'balance_minor' => $balance, 'overpayment_minor' => max(0, $net - $due), 'payment_status' => $balance === 0 ? 'paid' : ($net > 0 ? 'partially_paid' : 'unpaid')]);
    }

    public function payment(string $id, int $amount, string $method, string $reference, string $key, array $extra = []): array
    {
        return $this->records->transaction(function () use ($id, $amount, $method, $reference, $key, $extra) {
            $this->records->lock('invoices:'.$id);
            $invoice = $this->records->get('invoices', $id);
            $paymentId = hash('sha256', 'payment:'.$key);
            if ($existing = $this->records->find('payments', $paymentId)) {
                $this->ensure($existing['invoice_id'] === $id && $existing['amount_minor'] === $amount && $existing['method'] === $method, 'idempotency_key', 'This key already belongs to another payment.');

                return $existing;
            }
            $this->ensure(in_array($invoice['status'], ['issued', 'uncollectible'], true), 'status', 'Payments require an issued invoice.');
            $this->ensure($amount > 0 && $amount <= Money::MAX_MINOR, 'amount_minor', 'Enter a positive payment amount.');
            $payment = $this->records->create('payments', array_merge($extra, ['invoice_id' => $id, 'customer_id' => $invoice['customer_id'], 'currency' => $invoice['currency'], 'amount_minor' => $amount, 'method' => $method, 'reference' => $reference, 'status' => 'settled', 'recorded_by' => auth()->id(), 'settled_at' => now()->toIso8601String(), 'snapshot' => ['business' => Settings::get('business'), 'customer' => $this->publicCustomer($invoice['customer_id']), 'invoice_number' => $invoice['number'], 'currency' => $invoice['currency'], 'amount_minor' => $amount, 'method' => $method, 'reference' => $reference, 'receipt_number' => 'RCT-'.str_pad((string) $this->records->sequence('receipt-number'), 6, '0', STR_PAD_LEFT), 'settled_at' => now()->toIso8601String()]]), $paymentId);
            Outbox::audit('payment.received', ['payment_id' => $paymentId, 'invoice_id' => $id, 'amount_minor' => $amount]);
            Outbox::push('payment.received', ['invoice_id' => $id, 'payment_id' => $paymentId, 'customer_id' => $invoice['customer_id']], 'payment-received:'.$paymentId);

            return $payment;
        });
    }

    public function credit(string $id, int $amount, string $reason, string $key): array
    {
        return $this->records->transaction(function () use ($id, $amount, $reason, $key) {
            $this->records->lock('invoices:'.$id);
            $creditId = hash('sha256', 'credit:'.$key);
            if ($existing = $this->records->find('credit_notes', $creditId)) {
                $this->ensure($existing['invoice_id'] === $id && $existing['amount_minor'] === $amount, 'idempotency_key', 'This key already belongs to another credit.');

                return $existing;
            }
            $invoice = $this->balance($this->records->get('invoices', $id));
            $this->ensure(in_array($invoice['status'], ['issued', 'uncollectible'], true) && $amount > 0 && $amount <= $invoice['total_minor'] - $invoice['credit_minor'], 'amount_minor', 'Credit must be within the remaining invoice value.');

            return $this->records->create('credit_notes', ['invoice_id' => $id, 'customer_id' => $invoice['customer_id'], 'currency' => $invoice['currency'], 'amount_minor' => $amount, 'reason' => $reason, 'number' => 'CN-'.str_pad((string) $this->records->sequence('credit-number'), 6, '0', STR_PAD_LEFT), 'issued_at' => now()->toIso8601String(), 'issued_by' => auth()->id()], $creditId);
        });
    }

    public function refund(string $paymentId, int $amount, string $reason, string $key, string $status = 'settled', ?string $providerId = null): array
    {
        $payment = $this->records->get('payments', $paymentId);

        return $this->records->transaction(function () use ($payment, $amount, $reason, $key, $status, $providerId) {
            $this->records->lock('invoices:'.$payment['invoice_id']);
            $refundId = hash('sha256', 'refund:'.$key);
            if ($existing = $this->records->find('refunds', $refundId)) {
                $this->ensure($existing['payment_id'] === $payment['id'] && $existing['amount_minor'] === $amount, 'idempotency_key', 'This key already belongs to another refund.');
                if ($existing['status'] === 'pending' && $status === 'settled') {
                    return $this->records->update('refunds', $refundId, ['status' => 'settled', 'provider_id' => $providerId]);
                }

                return $existing;
            }
            $reserved = array_sum(array_map(fn (array $refund) => in_array($refund['status'], ['pending', 'settled'], true) ? $refund['amount_minor'] : 0, $this->records->all('refunds', ['payment_id' => $payment['id']])));
            $this->ensure($amount > 0 && $reserved + $amount <= $payment['amount_minor'], 'amount_minor', 'Refund exceeds the payment remaining after other refunds.');

            return $this->records->create('refunds', ['payment_id' => $payment['id'], 'invoice_id' => $payment['invoice_id'], 'customer_id' => $payment['customer_id'], 'currency' => $payment['currency'], 'amount_minor' => $amount, 'reason' => $reason, 'status' => $status, 'provider_id' => $providerId, 'recorded_by' => auth()->id()], $refundId);
        });
    }

    public function state(string $id, string $status, string $reason): array
    {
        return $this->records->transaction(function () use ($id, $status, $reason) {
            $this->records->lock('invoices:'.$id);
            $invoice = $this->balance($this->records->get('invoices', $id));
            $this->ensure($invoice['status'] === 'issued', 'status', 'Only issued invoices can change state.');
            $this->ensure($status !== 'void' || (count($this->records->all('payments', ['invoice_id' => $id])) === 0 && count($this->records->all('payment_attempts', ['invoice_id' => $id])) === 0 && $invoice['credit_minor'] === 0), 'status', 'An invoice with payments, checkout attempts, or credits cannot be voided; issue a credit note instead.');

            return $this->records->update('invoices', $id, ['status' => $status, 'state_reason' => $reason, 'state_changed_at' => now()->toIso8601String()]);
        });
    }

    public function deposit(array $input): array
    {
        $this->records->get('customers', $input['customer_id']);

        return $this->records->transaction(function () use ($input) {
            $id = hash('sha256', 'deposit:'.$input['idempotency_key']);
            $this->records->lock('deposit:'.$id);
            if ($existing = $this->records->find('customer_deposits', $id)) {
                $this->ensure($existing['customer_id'] === $input['customer_id'] && $existing['amount_minor'] === (int) $input['amount_minor'] && $existing['currency'] === $input['currency'], 'idempotency_key', 'This key already belongs to another deposit.');

                return $this->depositBalance($existing);
            }
            $deposit = $this->records->create('customer_deposits', ['customer_id' => $input['customer_id'], 'amount_minor' => (int) $input['amount_minor'], 'currency' => $input['currency'], 'method' => $input['method'], 'reference' => $input['reference'], 'status' => 'settled', 'received_at' => now()->toIso8601String(), 'recorded_by' => auth()->id()], $id);
            Outbox::audit('deposit.received', ['deposit_id' => $id, 'amount_minor' => $deposit['amount_minor']]);

            return $this->depositBalance($deposit);
        });
    }

    public function depositBalance(array $deposit): array
    {
        $allocated = array_sum(array_column($this->records->all('deposit_allocations', ['deposit_id' => $deposit['id']]), 'amount_minor'));
        $refunded = array_sum(array_column($this->records->all('deposit_refunds', ['deposit_id' => $deposit['id']]), 'amount_minor'));

        return array_merge($deposit, ['allocated_minor' => $allocated, 'refunded_minor' => $refunded, 'available_minor' => $deposit['amount_minor'] - $allocated - $refunded]);
    }

    public function allocateDeposit(string $id, string $invoiceId, int $amount, string $key): array
    {
        return $this->records->transaction(function () use ($id, $invoiceId, $amount, $key) {
            $this->records->lock('deposit:'.$id);
            $this->records->lock('invoices:'.$invoiceId);
            $allocationId = hash('sha256', 'allocation:'.$key);
            if ($existing = $this->records->find('deposit_allocations', $allocationId)) {
                $this->ensure($existing['deposit_id'] === $id && $existing['invoice_id'] === $invoiceId && $existing['amount_minor'] === $amount, 'idempotency_key', 'This key belongs to another allocation.');

                return $existing;
            }
            $deposit = $this->depositBalance($this->records->get('customer_deposits', $id));
            $invoice = $this->financeBalanceForAllocation($invoiceId, $deposit['customer_id'], $deposit['currency']);
            $this->ensure($amount > 0 && $amount <= $deposit['available_minor'] && $amount <= $invoice['balance_minor'], 'amount_minor', 'Allocation exceeds the available deposit or invoice balance.');
            $payment = $this->payment($invoiceId, $amount, 'deposit', $id, 'deposit-allocation:'.$key, ['deposit_id' => $id]);

            return $this->records->create('deposit_allocations', ['deposit_id' => $id, 'invoice_id' => $invoiceId, 'payment_id' => $payment['id'], 'amount_minor' => $amount, 'allocated_by' => auth()->id()], $allocationId);
        });
    }

    public function refundDeposit(string $id, int $amount, string $reason, string $key): array
    {
        return $this->records->transaction(function () use ($id, $amount, $reason, $key) {
            $this->records->lock('deposit:'.$id);
            $refundId = hash('sha256', 'deposit-refund:'.$key);
            if ($existing = $this->records->find('deposit_refunds', $refundId)) {
                $this->ensure($existing['deposit_id'] === $id && $existing['amount_minor'] === $amount, 'idempotency_key', 'This key belongs to another refund.');

                return $existing;
            }
            $deposit = $this->depositBalance($this->records->get('customer_deposits', $id));
            $this->ensure($amount > 0 && $amount <= $deposit['available_minor'], 'amount_minor', 'Refund exceeds the unallocated deposit.');
            Outbox::audit('deposit.refunded', ['deposit_id' => $id, 'amount_minor' => $amount]);

            return $this->records->create('deposit_refunds', ['deposit_id' => $id, 'customer_id' => $deposit['customer_id'], 'currency' => $deposit['currency'], 'amount_minor' => $amount, 'reason' => $reason, 'recorded_by' => auth()->id()], $refundId);
        });
    }

    public function transferCredit(string $id, string $invoiceId, int $amount, string $key): array
    {
        return $this->records->transaction(function () use ($id, $invoiceId, $amount, $key) {
            $ids = [$id, $invoiceId];
            sort($ids);
            foreach ($ids as $lockId) {
                $this->records->lock('invoices:'.$lockId);
            }
            $transferId = hash('sha256', 'credit-transfer:'.$key);
            if ($existing = $this->records->find('credit_transfers', $transferId)) {
                $this->ensure($existing['source_invoice_id'] === $id && $existing['invoice_id'] === $invoiceId && $existing['amount_minor'] === $amount, 'idempotency_key', 'This key belongs to another transfer.');

                return $existing;
            }
            $source = $this->balance($this->records->get('invoices', $id));
            $target = $this->financeBalanceForAllocation($invoiceId, $source['customer_id'], $source['currency']);
            $this->ensure($id !== $invoiceId && $amount > 0 && $amount <= $source['overpayment_minor'] && $amount <= $target['balance_minor'], 'amount_minor', 'Transfer exceeds the available customer credit or invoice balance.');
            $payment = $this->payment($invoiceId, $amount, 'customer_credit', $id, 'credit-transfer:'.$key, ['source_invoice_id' => $id]);

            return $this->records->create('credit_transfers', ['source_invoice_id' => $id, 'invoice_id' => $invoiceId, 'customer_id' => $source['customer_id'], 'currency' => $source['currency'], 'payment_id' => $payment['id'], 'amount_minor' => $amount, 'recorded_by' => auth()->id()], $transferId);
        });
    }

    private function financeBalanceForAllocation(string $id, string $customerId, string $currency): array
    {
        $invoice = $this->balance($this->records->get('invoices', $id));
        $this->ensure($invoice['customer_id'] === $customerId && $invoice['currency'] === $currency, 'invoice_id', 'Funds must stay with the same customer and currency.');

        return $invoice;
    }

    public function design(): array
    {
        return array_replace(['accent_color' => '#1f594e', 'font' => 'DejaVu Sans', 'paper' => 'letter', 'terms' => 'Thank you for your business.', 'footer' => '', 'payment_instructions' => '', 'show_quantity' => true, 'show_tax' => true], $this->records->find('settings', 'invoice_design') ?? []);
    }

    private function publicCustomer(string $id): array
    {
        return Arr::only($this->records->get('customers', $id), ['id', 'name', 'email', 'phone', 'company', 'address', 'billing_address', 'city', 'region', 'postal_code', 'country', 'tax_id']);
    }

    private function ensure(bool $condition, string $key, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages([$key => $message]);
        }
    }
}

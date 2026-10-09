<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace App\Finance;

use App\Contracts\RecordRepository;
use App\Support\Settings;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Stripe\Webhook;

class PaymentGateway
{
    public function __construct(private RecordRepository $records, private FinanceService $finance) {}

    public function availability(): array
    {
        $settings = Settings::get('payments');

        return ['stripe' => ! empty($settings['stripe_enabled']) && ! empty($settings['stripe_secret_key']) && ! empty($settings['stripe_webhook_secret']), 'paypal' => ! empty($settings['paypal_enabled']) && ! empty($settings['paypal_client_id']) && ! empty($settings['paypal_client_secret']) && ! empty($settings['paypal_webhook_id']), 'offline' => true];
    }

    public function checkout(string $invoiceId, string $provider): array
    {
        $this->enabled($provider);
        $attempt = $this->records->transaction(function () use ($invoiceId, $provider) {
            $this->records->lock('invoices:'.$invoiceId);
            $invoice = $this->finance->balance($this->records->get('invoices', $invoiceId));
            abort_unless(in_array($invoice['status'], ['issued', 'uncollectible'], true) && $invoice['balance_minor'] > 0, 422, 'This invoice has no payable balance.');
            foreach ($this->records->all('payment_attempts', ['invoice_id' => $invoiceId]) as $existing) {
                if ($existing['provider'] === $provider && $existing['amount_minor'] === $invoice['balance_minor'] && $existing['status'] === 'ready' && strtotime($existing['expires_at']) > time()) {
                    return $existing;
                }
            }

            return $this->records->create('payment_attempts', ['invoice_id' => $invoiceId, 'customer_id' => $invoice['customer_id'], 'provider' => $provider, 'status' => 'creating', 'currency' => $invoice['currency'], 'amount_minor' => $invoice['balance_minor'], 'invoice_number' => $invoice['number'], 'expires_at' => now()->addMinutes(30)->toIso8601String()]);
        });
        if (($attempt['status'] ?? null) === 'ready') {
            return $attempt;
        }
        $returnUrl = rtrim(config('app.url'), '/').'/app/invoices?payment=processing';
        if ($provider === 'stripe') {
            $response = $this->stripe()->asForm()->withHeaders(['Idempotency-Key' => 'checkout-'.$attempt['id']])->post('https://api.stripe.com/v1/checkout/sessions', [
                'mode' => 'payment', 'success_url' => $returnUrl, 'cancel_url' => rtrim(config('app.url'), '/').'/app/invoices?payment=cancelled',
                'expires_at' => now()->addMinutes(31)->timestamp,
                'line_items' => [['price_data' => ['currency' => strtolower($attempt['currency']), 'unit_amount' => $attempt['amount_minor'], 'product_data' => ['name' => 'Invoice '.$attempt['invoice_number']]], 'quantity' => 1]],
                'metadata' => ['invoice_id' => $invoiceId, 'attempt_id' => $attempt['id']],
                'payment_intent_data' => ['metadata' => ['invoice_id' => $invoiceId, 'attempt_id' => $attempt['id']]],
            ])->throw()->json();
            $url = $response['url'] ?? null;
        } else {
            $response = $this->paypal()->withHeaders(['PayPal-Request-Id' => $attempt['id']])->post($this->paypalBase().'/v2/checkout/orders', [
                'intent' => 'CAPTURE',
                'purchase_units' => [['reference_id' => $invoiceId, 'custom_id' => $attempt['id'], 'description' => 'Invoice '.$attempt['invoice_number'], 'amount' => ['currency_code' => $attempt['currency'], 'value' => (new Money($attempt['amount_minor'], $attempt['currency']))->format()]]],
                'payment_source' => ['paypal' => ['experience_context' => ['return_url' => $returnUrl, 'cancel_url' => rtrim(config('app.url'), '/').'/app/invoices?payment=cancelled', 'user_action' => 'PAY_NOW']]],
            ])->throw()->json();
            $url = collect($response['links'] ?? [])->first(fn (array $link) => in_array($link['rel'] ?? '', ['approve', 'payer-action'], true))['href'] ?? null;
        }
        abort_unless(is_string($url) && str_starts_with($url, 'https://') && ! empty($response['id']), 502, 'Payment provider returned an invalid checkout.');

        return $this->records->update('payment_attempts', $attempt['id'], ['status' => 'ready', 'provider_id' => $response['id'], 'url' => $url]);
    }

    public function stripeWebhook(Request $request): void
    {
        $this->enabled('stripe');
        abort_if(strlen($request->getContent()) > 2097152, 413);
        try {
            $event = Webhook::constructEvent($request->getContent(), $request->header('Stripe-Signature', ''), Settings::get('payments')['stripe_webhook_secret'])->toArray();
        } catch (\Exception $exception) {
            abort(400, 'Invalid payment signature.');
        }
        if ($this->records->find('gateway_events', 'stripe-'.$event['id'])) {
            return;
        }
        $object = $event['data']['object'];
        if (in_array($event['type'], ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true) && ($object['payment_status'] ?? '') === 'paid') {
            $attempt = $this->records->get('payment_attempts', $object['metadata']['attempt_id'] ?? '');
            abort_unless($attempt['provider'] === 'stripe' && $attempt['invoice_id'] === ($object['metadata']['invoice_id'] ?? '') && (! isset($attempt['provider_id']) || $attempt['provider_id'] === $object['id']), 422, 'Payment does not match its checkout.');
            $this->settle($attempt, (int) $object['amount_total'], strtoupper($object['currency']), $object['id'], ['payment_intent' => $object['payment_intent'] ?? null]);
        }
        if ($event['type'] === 'charge.refunded') {
            if ($payment = $this->paymentFor('payment_intent', $object['payment_intent'] ?? '')) {
                foreach ($object['refunds']['data'] ?? [] as $refund) {
                    if (($refund['status'] ?? '') === 'succeeded') {
                        $this->settleProviderRefund('stripe', $payment, (int) $refund['amount'], strtoupper($refund['currency']), $refund['id'], $refund['metadata']['local_refund_id'] ?? null);
                    }
                }
            } else {
                abort(409, 'Original payment has not arrived. Retry this event.');
            }
        }
        if (in_array($event['type'], ['charge.dispute.created', 'charge.dispute.closed'], true)) {
            if ($payment = $this->paymentFor('payment_intent', $object['payment_intent'] ?? '')) {
                abort_unless(strtoupper($object['currency'] ?? '') === $payment['currency'], 422, 'Dispute currency does not match payment.');
                $this->dispute('stripe', $object['id'], $payment, (int) $object['amount'], $object['status'] ?? 'needs_response');
            } else {
                abort(409, 'Original payment has not arrived. Retry this event.');
            }
        }
        $this->remember('stripe', $event['id'], $event['type']);
    }

    public function paypalWebhook(Request $request): void
    {
        $this->enabled('paypal');
        abort_if(strlen($request->getContent()) > 2097152, 413);
        $event = $request->json()->all();
        abort_unless(isset($event['id'], $event['event_type'], $event['resource']), 400);
        $verification = $this->paypal()->post($this->paypalBase().'/v1/notifications/verify-webhook-signature', [
            'auth_algo' => $request->header('PAYPAL-AUTH-ALGO'), 'cert_url' => $request->header('PAYPAL-CERT-URL'),
            'transmission_id' => $request->header('PAYPAL-TRANSMISSION-ID'), 'transmission_sig' => $request->header('PAYPAL-TRANSMISSION-SIG'),
            'transmission_time' => $request->header('PAYPAL-TRANSMISSION-TIME'), 'webhook_id' => Settings::get('payments')['paypal_webhook_id'], 'webhook_event' => $event,
        ])->throw()->json();
        abort_unless(($verification['verification_status'] ?? '') === 'SUCCESS', 400, 'Invalid payment signature.');
        if ($this->records->find('gateway_events', 'paypal-'.$event['id'])) {
            return;
        }
        $object = $event['resource'];
        if ($event['event_type'] === 'CHECKOUT.ORDER.APPROVED') {
            $attempt = $this->attemptFor('paypal', $object['id']);
            $this->capturePaypal($attempt);
        }
        if ($event['event_type'] === 'PAYMENT.CAPTURE.COMPLETED') {
            $attempt = $this->attemptFor('paypal', $object['supplementary_data']['related_ids']['order_id'] ?? '');
            $this->settle($attempt, Money::decimal($object['amount']['value'], $object['amount']['currency_code'])->minor, $object['amount']['currency_code'], $object['id'], ['capture_id' => $object['id']]);
        }
        if ($event['event_type'] === 'PAYMENT.CAPTURE.REFUNDED') {
            $captureId = $object['supplementary_data']['related_ids']['capture_id'] ?? '';
            if (! $captureId) {
                foreach ($object['links'] ?? [] as $link) {
                    if (($link['rel'] ?? '') === 'up' && preg_match('~/v2/payments/captures/([a-zA-Z0-9]+)$~', $link['href'] ?? '', $matches)) {
                        $captureId = $matches[1];
                    }
                }
            }
            $payment = $this->paymentFor('capture_id', $captureId);
            abort_unless($payment, 409, 'Original payment has not arrived. Retry this event.');
            $this->settleProviderRefund('paypal', $payment, Money::decimal($object['amount']['value'], $object['amount']['currency_code'])->minor, $object['amount']['currency_code'], $object['id']);
        }
        if ($event['event_type'] === 'PAYMENT.CAPTURE.REVERSED') {
            $payment = $this->paymentFor('capture_id', $object['id']);
            abort_unless($payment, 409, 'Original payment has not arrived. Retry this event.');
            abort_unless($object['amount']['currency_code'] === $payment['currency'], 422, 'Reversal currency does not match payment.');
            $this->dispute('paypal', $object['id'], $payment, Money::decimal($object['amount']['value'], $object['amount']['currency_code'])->minor, 'lost');
        }
        $this->remember('paypal', $event['id'], $event['event_type']);
    }

    public function reconcile(string $id): array
    {
        $attempt = $this->records->get('payment_attempts', $id);
        $this->enabled($attempt['provider']);
        if ($attempt['provider'] === 'stripe') {
            $session = $this->stripe()->get('https://api.stripe.com/v1/checkout/sessions/'.rawurlencode($attempt['provider_id']))->throw()->json();
            if (($session['payment_status'] ?? '') === 'paid') {
                $this->settle($attempt, (int) $session['amount_total'], strtoupper($session['currency']), $session['id'], ['payment_intent' => $session['payment_intent'] ?? null]);
            }
        } else {
            $order = $this->paypal()->get($this->paypalBase().'/v2/checkout/orders/'.rawurlencode($attempt['provider_id']))->throw()->json();
            if (($order['status'] ?? '') === 'APPROVED') {
                $this->capturePaypal($attempt);
            } elseif (($order['status'] ?? '') === 'COMPLETED') {
                $this->settlePaypalOrder($attempt, $order);
            }
        }

        return $this->records->get('payment_attempts', $id);
    }

    public function refund(array $payment, int $amount, string $reason, string $key): array
    {
        $provider = $payment['method'];
        if (! in_array($provider, ['stripe', 'paypal'], true)) {
            return $this->finance->refund($payment['id'], $amount, $reason, $key);
        }
        $this->enabled($provider);
        $reservation = $this->finance->refund($payment['id'], $amount, $reason, $key, 'pending');
        if ($reservation['status'] === 'settled') {
            return $reservation;
        }
        if ($provider === 'stripe') {
            $response = $this->stripe()->asForm()->withHeaders(['Idempotency-Key' => $reservation['id']])->post('https://api.stripe.com/v1/refunds', ['payment_intent' => $payment['payment_intent'], 'amount' => $amount, 'metadata' => ['local_refund_id' => $reservation['id']]])->throw()->json();
            $settled = ($response['status'] ?? '') === 'succeeded';
        } else {
            $response = $this->paypal()->withHeaders(['PayPal-Request-Id' => $reservation['id']])->post($this->paypalBase().'/v2/payments/captures/'.rawurlencode($payment['capture_id']).'/refund', ['amount' => ['value' => (new Money($amount, $payment['currency']))->format(), 'currency_code' => $payment['currency']], 'note_to_payer' => Str::limit($reason, 255, '')])->throw()->json();
            $settled = ($response['status'] ?? '') === 'COMPLETED';
        }

        return $this->records->update('refunds', $reservation['id'], ['status' => $settled ? 'settled' : 'pending', 'provider_id' => $response['id']]);
    }

    private function settleProviderRefund(string $provider, array $payment, int $amount, string $currency, string $providerId, ?string $localId = null): void
    {
        abort_unless($payment['currency'] === $currency, 422, 'Refund currency does not match payment.');
        $this->records->transaction(function () use ($provider, $payment, $amount, $providerId, $localId) {
            $this->records->lock('invoices:'.$payment['invoice_id']);
            foreach ($this->records->all('refunds', ['payment_id' => $payment['id']]) as $refund) {
                if (($refund['provider_id'] ?? null) === $providerId || ($localId && $refund['id'] === $localId)) {
                    abort_unless($refund['amount_minor'] === $amount, 422, 'Refund amount does not match the reserved refund.');
                    $this->records->update('refunds', $refund['id'], ['status' => 'settled', 'provider_id' => $providerId]);

                    return;
                }
            }
            $this->finance->refund($payment['id'], $amount, 'Provider refund', $provider.':'.$providerId, 'settled', $providerId);
        });
    }

    private function capturePaypal(array $attempt): void
    {
        $order = $this->paypal()->withHeaders(['PayPal-Request-Id' => 'capture-'.$attempt['id']])->withBody('{}', 'application/json')->post($this->paypalBase().'/v2/checkout/orders/'.rawurlencode($attempt['provider_id']).'/capture')->throw()->json();
        $this->settlePaypalOrder($attempt, $order);
    }

    private function settlePaypalOrder(array $attempt, array $order): void
    {
        foreach ($order['purchase_units'] ?? [] as $unit) {
            foreach ($unit['payments']['captures'] ?? [] as $capture) {
                if (($capture['status'] ?? '') === 'COMPLETED') {
                    $this->settle($attempt, Money::decimal($capture['amount']['value'], $capture['amount']['currency_code'])->minor, $capture['amount']['currency_code'], $capture['id'], ['capture_id' => $capture['id']]);
                }
            }
        }
    }

    private function settle(array $attempt, int $amount, string $currency, string $reference, array $extra): void
    {
        abort_unless($amount === $attempt['amount_minor'] && $currency === $attempt['currency'], 422, 'Provider payment amount or currency does not match checkout.');
        $this->records->transaction(function () use ($attempt, $amount, $reference, $extra) {
            $this->finance->payment($attempt['invoice_id'], $amount, $attempt['provider'], $reference, $attempt['provider'].':'.$reference, array_merge($extra, ['attempt_id' => $attempt['id']]));
            $this->records->update('payment_attempts', $attempt['id'], ['status' => 'settled', 'settled_reference' => $reference]);
        });
    }

    private function dispute(string $provider, string $id, array $payment, int $amount, string $status): void
    {
        $this->records->transaction(function () use ($provider, $id, $payment, $amount, $status) {
            $this->records->lock('invoices:'.$payment['invoice_id']);
            $recordId = $provider.'-'.$id;
            abort_unless($amount > 0 && $amount <= $payment['amount_minor'], 422, 'Dispute amount exceeds the original payment.');
            $existing = $this->records->find('disputes', $recordId);
            if ($existing && in_array($existing['status'], ['won', 'lost'], true) && ! in_array($status, ['won', 'lost'], true)) {
                return;
            }
            $data = ['invoice_id' => $payment['invoice_id'], 'payment_id' => $payment['id'], 'amount_minor' => $amount, 'provider' => $provider, 'status' => in_array($status, ['won', 'lost'], true) ? $status : 'open'];
            if ($this->records->find('disputes', $recordId)) {
                $this->records->update('disputes', $recordId, $data);
            } else {
                $this->records->create('disputes', $data, $recordId);
            }
            if ($status === 'lost' && ! $this->records->find('reversals', $recordId)) {
                $this->records->create('reversals', $data, $recordId);
            }
        });
    }

    private function attemptFor(string $provider, string $id): array
    {
        foreach ($this->records->all('payment_attempts', ['provider' => $provider]) as $attempt) {
            if (($attempt['provider_id'] ?? null) === $id) {
                return $attempt;
            }
        }
        abort(409, 'Checkout is not ready. Retry this event.');
    }

    private function paymentFor(string $field, string $id): ?array
    {
        if ($id === '') {
            return null;
        }

        return $this->records->all('payments', [$field => $id])[0] ?? null;
    }

    private function remember(string $provider, string $id, string $type): void
    {
        $this->records->transaction(function () use ($provider, $id, $type) {
            $this->records->lock('gateway:'.$provider.':'.$id);
            if (! $this->records->find('gateway_events', $provider.'-'.$id)) {
                $this->records->create('gateway_events', ['provider' => $provider, 'event_type' => $type, 'processed_at' => now()->toIso8601String()], $provider.'-'.$id);
            }
        });
    }

    private function enabled(string $provider): void
    {
        if (! ($this->availability()[$provider] ?? false)) {
            throw ValidationException::withMessages(['provider' => ucfirst($provider).' is disabled until credentials and webhook verification are configured.']);
        }
    }

    private function stripe(): PendingRequest
    {
        return Http::withToken(Settings::get('payments')['stripe_secret_key'])->acceptJson()->timeout(20);
    }

    private function paypalBase(): string
    {
        return (Settings::get('payments')['paypal_mode'] ?? 'sandbox') === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
    }

    private function paypal(): PendingRequest
    {
        $settings = Settings::get('payments');
        $token = Http::asForm()->withBasicAuth($settings['paypal_client_id'], $settings['paypal_client_secret'])->timeout(15)->post($this->paypalBase().'/v1/oauth2/token', ['grant_type' => 'client_credentials'])->throw()->json('access_token');
        abort_unless(is_string($token) && $token !== '', 502, 'Payment provider authentication failed.');

        return Http::withToken($token)->acceptJson()->timeout(20);
    }
}

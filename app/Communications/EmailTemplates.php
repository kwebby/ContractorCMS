<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace App\Communications;

use App\Contracts\RecordRepository;
use App\Support\Outbox;
use App\Support\Settings;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class EmailTemplates
{
    private const VARIABLES = ['recipient_name', 'business_name', 'action_url', 'document_number', 'total', 'service', 'appointment_at', 'message', 'role'];

    private const ACTION_REQUIRED = ['invitation', 'verification', 'password_reset', 'estimate', 'invoice', 'payslip'];

    public function __construct(private readonly RecordRepository $records) {}

    public static function defaults(): array
    {
        return [
            'invitation' => ['subject' => 'Join {{ business_name }}', 'body' => "Hello {{ recipient_name }},\n\nYou have been invited to join {{ business_name }} as {{ role }}. Set up your account here:\n{{ action_url }}\n\nIf you did not expect this invitation, you can ignore this email."],
            'verification' => ['subject' => 'Confirm your email address', 'body' => "Hello {{ recipient_name }},\n\nConfirm your email address for {{ business_name }}:\n{{ action_url }}\n\nIf you did not request this email, you can ignore it."],
            'password_reset' => ['subject' => 'Reset your password', 'body' => "Hello {{ recipient_name }},\n\nUse this link to reset your password:\n{{ action_url }}\n\nIf you did not request a reset, you can ignore this email."],
            'inquiry' => ['subject' => 'We received your service request', 'body' => "Hello {{ recipient_name }},\n\nThank you for contacting {{ business_name }} about {{ service }}. Our team will review your request and discuss the next step.\n\nA request is not yet a confirmed appointment.\n{{ message }}"],
            'estimate' => ['subject' => 'Your estimate {{ document_number }} is ready', 'body' => "Hello {{ recipient_name }},\n\nYour estimate {{ document_number }} from {{ business_name }} is ready to review.\nTotal: {{ total }}\n\nReview the scope and options here:\n{{ action_url }}"],
            'appointment' => ['subject' => 'Appointment update from {{ business_name }}', 'body' => "Hello {{ recipient_name }},\n\n{{ message }}\nService: {{ service }}\nScheduled time: {{ appointment_at }}\n\n{{ action_url }}"],
            'message' => ['subject' => 'A new message from {{ business_name }}', 'body' => "Hello {{ recipient_name }},\n\n{{ message }}\n\nOpen your conversation:\n{{ action_url }}"],
            'invoice' => ['subject' => 'Invoice {{ document_number }} from {{ business_name }}', 'body' => "Hello {{ recipient_name }},\n\nYour invoice {{ document_number }} is available.\nTotal: {{ total }}\n\nView the invoice and payment options:\n{{ action_url }}"],
            'receipt' => ['subject' => 'Payment receipt from {{ business_name }}', 'body' => "Hello {{ recipient_name }},\n\nWe recorded a payment of {{ total }} against {{ document_number }}.\n{{ message }}\n\nView your records:\n{{ action_url }}"],
            'refund' => ['subject' => 'Refund update from {{ business_name }}', 'body' => "Hello {{ recipient_name }},\n\n{{ message }}\nDocument: {{ document_number }}\nAmount: {{ total }}\n\n{{ action_url }}"],
            'payslip' => ['subject' => 'Your payslip is available', 'body' => "Hello {{ recipient_name }},\n\nA new payslip from {{ business_name }} is available in your private employee workspace. Sign in to view it:\n{{ action_url }}\n\nPlease contact your payroll administrator with questions."],
        ];
    }

    public function all(): array
    {
        return array_map(fn (string $key): array => $this->get($key), array_keys(self::defaults()));
    }

    public function get(string $key): array
    {
        abort_unless(isset(self::defaults()[$key]), 404);
        $record = $this->records->find('email_templates', $key);

        return ($record ?? ['id' => $key, 'key' => $key, 'version' => 0] + self::defaults()[$key]) + [
            'placeholders' => self::VARIABLES,
            'required_placeholders' => in_array($key, self::ACTION_REQUIRED, true) ? ['action_url'] : [],
        ];
    }

    public function update(string $key, array $input): array
    {
        $this->get($key);
        $data = Validator::make($input, [
            'subject' => ['required', 'string', 'max:200', 'not_regex:/[\r\n]/'],
            'body' => ['required', 'string', 'max:12000'],
            'expected_version' => ['required', 'integer', 'min:0'],
        ])->validate();
        $this->validatePlaceholders($data['subject'].' '.$data['body']);

        return $this->records->transaction(function () use ($key, $data): array {
            $this->records->lock('email-template:'.$key);
            $old = $this->get($key);
            abort_unless($old['version'] === $data['expected_version'], 409, 'This template changed. Reload it before saving.');
            $this->records->create('email_template_revisions', ['key' => $key, 'template_version' => $old['version'], 'subject' => $old['subject'], 'body' => $old['body'], 'author_id' => auth()->id()]);
            $values = ['key' => $key, 'subject' => $data['subject'], 'body' => $data['body']];
            if ($old['version'] === 0) {
                $this->records->create('email_templates', $values, $key);
            } else {
                $this->records->update('email_templates', $key, $values, $old['version']);
            }
            Outbox::audit('email_template.updated', ['key' => $key, 'previous_version' => $old['version']]);

            return $this->get($key);
        });
    }

    public function render(string $key, array $variables): array
    {
        return $this->renderSaved($this->get($key), $variables);
    }

    public function renderSaved(array $template, array $variables): array
    {
        $key = $template['key'];
        abort_unless(isset(self::defaults()[$key]), 422);
        $business = Settings::get('business');
        $variables += ['business_name' => $business['name'] ?? $business['legal_name'] ?? 'Our team', 'recipient_name' => 'there'];
        foreach ($variables as $name => $value) {
            if (! in_array($name, self::VARIABLES, true) || ! is_scalar($value) && $value !== null || strlen((string) $value) > 15000) {
                throw ValidationException::withMessages(['template_data' => 'Template values must use supported placeholders and bounded plain text.']);
            }
        }
        if (in_array($key, self::ACTION_REQUIRED, true)) {
            $url = $variables['action_url'] ?? '';
            if (! is_string($url) || ! filter_var($url, FILTER_VALIDATE_URL) || ! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
                throw ValidationException::withMessages(['template_data.action_url' => 'This email requires a valid action link.']);
            }
        }
        $replace = fn (string $text): string => preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', fn (array $match): string => (string) ($variables[$match[1]] ?? ''), $text);
        $subject = str_replace(["\r", "\n"], ' ', $replace($template['subject']));
        $body = $replace($template['body']);
        if (strlen($body) > 64000) {
            throw ValidationException::withMessages(['body' => 'Rendered email exceeds 64 KB. Shorten the message or remove repeated placeholders.']);
        }
        if (in_array($key, self::ACTION_REQUIRED, true) && ! str_contains($body, $variables['action_url'])) {
            $body .= "\n\nContinue securely:\n".$variables['action_url'];
        }

        return ['subject' => mb_substr($subject, 0, 200), 'body' => $body, 'template_key' => $key, 'template_version' => $template['version']];
    }

    public static function snapshot(array $payload): array
    {
        if (isset($payload['template_key'])) {
            $rendered = isset($payload['_template_snapshot'])
                ? app(self::class)->renderSaved($payload['_template_snapshot'], $payload['template_data'] ?? [])
                : app(self::class)->render($payload['template_key'], $payload['template_data'] ?? []);
            $payload = array_replace($payload, $rendered);
            unset($payload['template_data'], $payload['text'], $payload['_template_snapshot']);
        } elseif (! isset($payload['body']) && isset($payload['text'])) {
            $payload['body'] = $payload['text'];
        }

        return $payload;
    }

    public static function snapshotEvent(string $type, array $payload): array
    {
        $key = match ($type) {
            'estimate.sent' => 'estimate', 'estimate.accepted' => 'message', 'invoice.issued' => 'invoice',
            'payment.received' => 'receipt', 'payslip.published' => 'payslip', default => null,
        };
        if ($key !== null) {
            $template = app(self::class)->get($key);
            $payload['_template_snapshot'] = array_intersect_key($template, array_flip(['key', 'subject', 'body', 'version']));
            $payload['_email_business_name'] = Settings::get('business')['name'] ?? 'Our team';
        }

        return $payload;
    }

    private function validatePlaceholders(string $text): void
    {
        preg_match_all('/\{\{\s*([a-z_]+)\s*\}\}/', $text, $matches);
        if (count($matches[0]) > 50) {
            throw ValidationException::withMessages(['body' => 'Use at most 50 placeholders per email template.']);
        }
        if (array_diff($matches[1], self::VARIABLES)) {
            throw ValidationException::withMessages(['body' => 'Use only the supported email placeholders.']);
        }
        $plain = preg_replace('/\{\{\s*([a-z_]+)\s*\}\}/', '', $text);
        if (str_contains($plain, '{{') || str_contains($plain, '}}') || str_contains($plain, '{%') || str_contains($plain, '%}')) {
            throw ValidationException::withMessages(['body' => 'Templates support plain placeholders only; executable expressions are not allowed.']);
        }
    }
}

<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace App\Automation;

use App\Contracts\RecordRepository;
use App\Support\Outbox;
use App\Support\Settings;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class LeadToolService
{
    public const TOOLS = [
        'service-preparation' => ['name' => 'Service-visit preparation', 'description' => 'Turn symptoms, timing, and access details into a useful brief for your contractor.', 'placeholder' => 'What needs attention? When did it start? Include any access or scheduling details.'],
        'project-scope' => ['name' => 'Project scope generator', 'description' => 'Organize your goals, preferences, and open decisions before you speak with a contractor.', 'placeholder' => 'Describe the project, what you want to change, and any priorities or constraints.'],
        'quote-comparison' => ['name' => 'Quote comparison organizer', 'description' => 'Compare what your quotes actually include and identify questions to clarify.', 'placeholder' => 'Paste two or more quotes, labeled Quote A, Quote B, and so on. Remove personal and payment information first.'],
    ];

    public function __construct(private readonly RecordRepository $records, private readonly OpenAiGateway $gateway) {}

    public function canonical(string $tool): string
    {
        $tool = $tool === 'service-visit' ? 'service-preparation' : $tool;
        abort_unless(isset(self::TOOLS[$tool]), 404);

        return $tool;
    }

    public function available(): bool
    {
        $settings = Settings::get('ai');

        return ! empty($settings['enabled']) && ! empty($settings['api_key']) && ! empty($settings['model']);
    }

    public function generate(string $tool, string $details, string $requestId, string $visitor): array
    {
        $tool = $this->canonical($tool);
        $settings = Settings::get('ai');
        if (! $this->available()) {
            throw new HttpException(503, 'This planning tool is currently unavailable. Please send a service request instead.');
        }
        $id = hash('sha256', $visitor.'|'.$tool.'|'.$requestId);
        $inputHash = hash('sha256', $details);
        $day = now()->utc()->format('Y-m-d');
        $reservation = strlen($details) + strlen($this->gateway->instructions($tool)) + OpenAiGateway::MAX_OUTPUT_TOKENS + 2000;
        $run = $this->records->transaction(function () use ($id, $inputHash, $details, $settings, $tool, $visitor, $day, $reservation): array {
            $this->records->lock('tool-generation:'.$id);
            $existing = $this->records->find('tool_runs', $id);
            if ($existing) {
                abort_unless($existing['input_hash'] === $inputHash, 409, 'This request ID was already used for different input.');

                return $existing;
            }
            $this->records->lock('ai-budget:'.$day);
            $budget = $this->records->find('ai_usage', $day) ?? ['requests' => 0, 'tokens' => 0];
            if ($budget['requests'] >= ($settings['daily_request_limit'] ?? 100) || $budget['tokens'] + $reservation > ($settings['daily_token_limit'] ?? 100000)) {
                throw new HttpException(429, 'The daily planning-tool allowance has been reached. Please contact the team directly.');
            }
            $totals = ['requests' => $budget['requests'] + 1, 'tokens' => $budget['tokens'] + $reservation];
            if (isset($budget['id'])) {
                $this->records->update('ai_usage', $day, $totals);
            } else {
                $this->records->create('ai_usage', $totals, $day);
            }

            return $this->records->create('tool_runs', [
                'tool' => $tool, 'status' => 'processing', 'visitor_hash' => $visitor, 'input_hash' => $inputHash, 'input' => $details,
                'reservation' => $reservation, 'budget_day' => $day, 'expires_at' => now()->addDays(7)->toIso8601String(),
            ], $id);
        });
        if ($run['status'] === 'completed') {
            return $this->publicResult($run);
        }
        if ($run['status'] === 'failed') {
            throw new HttpException(502, 'The previous attempt could not complete. Start a new request to try again.');
        }
        $claim = $this->records->transaction(function () use ($id): bool {
            $this->records->lock('tool-generation:'.$id);
            $run = $this->records->get('tool_runs', $id);
            if (! empty($run['started_at'])) {
                return false;
            }
            $this->records->update('tool_runs', $id, ['started_at' => now()->toIso8601String()]);

            return true;
        });
        if (! $claim) {
            throw new HttpException(409, 'This brief is already being prepared. Please wait before retrying.');
        }
        try {
            $response = $this->gateway->generate($tool, ['details' => $details, 'request_id' => $requestId], $settings);
            $run = $this->records->transaction(function () use ($id, $response, $day, $reservation): array {
                $this->records->lock('ai-budget:'.$day);
                $budget = $this->records->get('ai_usage', $day);
                $usage = $response['tokens'] > 0 ? $response['tokens'] : $reservation;
                $this->records->update('ai_usage', $day, ['tokens' => max(0, $budget['tokens'] - $reservation + $usage)]);

                return $this->records->update('tool_runs', $id, ['status' => 'completed', 'result' => $response['result'], 'tokens' => $usage, 'provider_id' => $response['provider_id']]);
            });
        } catch (\Throwable) {
            $this->records->update('tool_runs', $id, ['status' => 'failed', 'failure' => 'provider_or_validation_failure']);
            throw new HttpException(502, 'We could not prepare this brief. Please try again later or contact the team.');
        }

        return $this->publicResult($run);
    }

    public function requestReport(string $runId, string $visitor, array $contact): void
    {
        $this->records->transaction(function () use ($runId, $visitor, $contact): void {
            $this->records->lock('tool-report:'.$runId);
            $run = $this->records->get('tool_runs', $runId);
            abort_unless(hash_equals($run['visitor_hash'], $visitor), 404);
            abort_unless($run['status'] === 'completed' && strtotime($run['expires_at']) > time(), 410, 'This report is no longer available.');
            $email = strtolower($contact['email']);
            $id = hash('sha256', $runId.'|'.$email);
            $existing = $this->records->find('tool_report_requests', $id);
            if ($existing) {
                return;
            }
            $token = Str::random(64);
            $this->records->create('tool_report_requests', [
                'run_id' => $runId, 'name' => $contact['name'], 'email' => $email,
                'marketing_consent' => (bool) ($contact['marketing_consent'] ?? false),
                'contact_requested' => (bool) ($contact['contact_requested'] ?? false),
                'token_hash' => hash('sha256', $token), 'status' => 'pending', 'expires_at' => now()->addHours(24)->toIso8601String(),
            ], $id);
            $this->records->create('tool_report_tokens', ['request_id' => $id], hash('sha256', $token));
            Outbox::push('email', [
                'to' => $email, 'subject' => 'Confirm your email to receive your planning brief',
                'body' => 'Confirm that this is your email address to receive the planning brief you requested: '.rtrim(config('app.url'), '/').'/tools/reports/verify/'.$token."\n\nThis link expires in 24 hours. If you did not request this email, you can ignore it.",
            ], 'tool-report-verify:'.$id);
        });
    }

    public function verifyReport(string $token): array
    {
        return $this->records->transaction(function () use ($token): array {
            $hash = hash('sha256', $token);
            $this->records->lock('tool-verification:'.$hash);
            $tokenRecord = $this->records->get('tool_report_tokens', $hash);
            $request = $this->records->get('tool_report_requests', $tokenRecord['request_id']);
            abort_unless(strtotime($request['expires_at']) > time(), 410, 'This confirmation link has expired.');
            $run = $this->records->get('tool_runs', $request['run_id']);
            abort_unless($run['status'] === 'completed', 410);
            if ($request['status'] !== 'verified') {
                $this->records->update('tool_report_requests', $request['id'], ['status' => 'verified', 'verified_at' => now()->toIso8601String()]);
                $lead = $this->records->create('leads', [
                    'name' => $request['name'], 'email' => $request['email'], 'email_verified_at' => now()->toIso8601String(),
                    'source' => 'ai-tool', 'tool' => $run['tool'], 'tool_run_id' => $run['id'], 'stage' => 'new', 'priority' => 'normal',
                    'service' => self::TOOLS[$run['tool']]['name'], 'notes' => $run['result']['summary'],
                    'marketing_consent' => $request['marketing_consent'], 'contact_requested' => $request['contact_requested'],
                    'marketing_consent_at' => $request['marketing_consent'] ? now()->toIso8601String() : null,
                    'consent_recorded_at' => now()->toIso8601String(), 'owner_id' => null,
                ]);
                $this->records->update('tool_report_requests', $request['id'], ['lead_id' => $lead['id']]);
                $body = self::TOOLS[$run['tool']]['name']."\n\n".$run['result']['summary'];
                foreach (['known_facts' => 'What you told us', 'questions' => 'Questions to discuss', 'next_steps' => 'Planning steps', 'unknowns' => 'Still to clarify'] as $key => $heading) {
                    $body .= "\n\n".$heading."\n".implode("\n", array_map(fn (string $item): string => '• '.$item, $run['result'][$key]));
                }
                $body .= "\n\nThis AI-assisted organizing draft is for discussion with a qualified contractor. It is not an assessment or a quote.";
                Outbox::push('email', ['to' => $request['email'], 'subject' => 'Your planning brief', 'body' => $body], 'tool-report-delivery:'.$request['id']);
                foreach ($this->records->all('users') as $user) {
                    if (in_array($user['role'] ?? '', ['owner', 'office', 'estimator'], true)) {
                        Outbox::notify($user['id'], 'A verified planning-tool lead is ready', 'crm', '/app/leads');
                    }
                }
            }

            return $this->publicResult($run);
        });
    }

    private function publicResult(array $run): array
    {
        return ['id' => $run['id'], 'tool' => $run['tool'], 'status' => $run['status'], 'result' => $run['result']];
    }
}

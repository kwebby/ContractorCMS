<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace App\Automation;

use App\Contracts\RecordRepository;
use App\Support\Outbox;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class LeadToolService
{
    public const TOOLS = [
        'service-preparation' => ['name' => 'Service-visit preparation', 'description' => 'Turn symptoms, timing, and access details into a useful brief for your contractor.', 'placeholder' => 'What needs attention? When did it start? Include any access or scheduling details.'],
        'project-scope' => ['name' => 'Project scope generator', 'description' => 'Organize your goals, preferences, and open decisions before you speak with a contractor.', 'placeholder' => 'Describe the project, what you want to change, and any priorities or constraints.'],
        'quote-comparison' => ['name' => 'Quote comparison organizer', 'description' => 'Compare what your quotes actually include and identify questions to clarify.', 'placeholder' => 'Paste two or more quotes, labeled Quote A, Quote B, and so on. Remove personal and payment information first.'],
    ];

    private const RETENTION_DAYS = 7;

    private const GENERATION_LEASE_SECONDS = 120;

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
            $this->reserve($day, $reservation, $settings);

            return $this->records->create('tool_runs', [
                'tool' => $tool, 'status' => 'processing', 'visitor_hash' => $visitor, 'input_hash' => $inputHash, 'input' => $details,
                'reservation' => $reservation, 'budget_day' => $day, 'expires_at' => now()->addDays(self::RETENTION_DAYS)->toIso8601String(),
            ], $id);
        });
        if ($run['status'] === 'processing') {
            $run = $this->claim($id, $day, $reservation, $settings);
        }
        if ($run['status'] === 'completed') {
            return $this->publicResult($run);
        }
        if ($run['status'] === 'failed') {
            throw new HttpException(502, 'The previous attempt could not complete. Start a new request to try again.');
        }
        try {
            $response = $this->gateway->generate($tool, ['details' => $details, 'request_id' => $requestId], $settings);
            $run = $this->records->transaction(function () use ($id, $response, $run): array {
                $this->records->lock('ai-budget:'.$run['budget_day']);
                $budget = $this->records->get('ai_usage', $run['budget_day']);
                $usage = $response['tokens'] > 0 ? $response['tokens'] : $run['reservation'];
                $this->records->update('ai_usage', $run['budget_day'], ['tokens' => max(0, $budget['tokens'] - $run['reservation'] + $usage)]);

                return $this->records->update('tool_runs', $id, ['status' => 'completed', 'result' => $response['result'], 'tokens' => $usage, 'provider_id' => $response['provider_id']]);
            });
        } catch (\Throwable) {
            $this->records->update('tool_runs', $id, ['status' => 'failed', 'failure' => 'provider_or_validation_failure']);
            throw new HttpException(502, 'We could not prepare this brief. Please try again later or contact the team.');
        }

        return $this->publicResult($run);
    }

    /**
     * Start the provider call for a processing run. A run whose previous attempt stopped without
     * finishing (its lease expired) is reclaimed; that attempt's reservation stays spent because the
     * provider may have billed it, and the new attempt reserves its own allowance.
     */
    private function claim(string $id, string $day, int $reservation, array $settings): array
    {
        return $this->records->transaction(function () use ($id, $day, $reservation, $settings): array {
            $this->records->lock('tool-generation:'.$id);
            $run = $this->records->get('tool_runs', $id);
            if ($run['status'] !== 'processing') {
                return $run;
            }
            if (empty($run['started_at'])) {
                return $this->records->update('tool_runs', $id, ['started_at' => now()->toIso8601String()]);
            }
            if (Carbon::parse($run['started_at'])->gt(now()->subSeconds(self::GENERATION_LEASE_SECONDS))) {
                throw new HttpException(409, 'This brief is already being prepared. Please wait before retrying.');
            }
            $this->reserve($day, $reservation, $settings);

            return $this->records->update('tool_runs', $id, ['started_at' => now()->toIso8601String(), 'reservation' => $reservation, 'budget_day' => $day, 'reclaimed_attempts' => ($run['reclaimed_attempts'] ?? 0) + 1]);
        });
    }

    private function reserve(string $day, int $reservation, array $settings): void
    {
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
            if ($existing && ($existing['status'] !== 'pending' || strtotime($existing['expires_at']) > time())) {
                return;
            }
            if ($existing) {
                $this->records->delete('tool_report_tokens', $existing['token_hash']);
            }
            $token = Str::random(64);
            $values = [
                'run_id' => $runId, 'name' => $contact['name'], 'email' => $email,
                'marketing_consent' => (bool) ($contact['marketing_consent'] ?? false),
                'contact_requested' => (bool) ($contact['contact_requested'] ?? false),
                'token_hash' => hash('sha256', $token), 'status' => 'pending', 'expires_at' => now()->addHours(24)->toIso8601String(),
            ];
            if ($existing) {
                $this->records->update('tool_report_requests', $id, $values);
            } else {
                $this->records->create('tool_report_requests', $values, $id);
            }
            $this->records->create('tool_report_tokens', ['request_id' => $id], hash('sha256', $token));
            Outbox::push('email', [
                'to' => $email, 'subject' => 'Confirm your email to receive your planning brief',
                'body' => 'Confirm that this is your email address to receive the planning brief you requested: '.rtrim(config('app.url'), '/').'/tools/reports/verify/'.$token."\n\nThis link expires in 24 hours. If you did not request this email, you can ignore it.",
            ], 'tool-report-verify:'.$id.($existing ? ':'.hash('sha256', $token) : ''));
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

    /**
     * Delete visitor input, report requests and confirmation tokens once they expire. Leads created
     * from verified requests keep their own copy of the consented contact details.
     *
     * @return array{tool_runs: int, tool_report_requests: int, tool_report_tokens: int}
     */
    public function purgeExpired(): array
    {
        $deleted = ['tool_runs' => 0, 'tool_report_requests' => 0, 'tool_report_tokens' => 0];
        foreach ($this->records->all('tool_report_requests') as $candidate) {
            $removed = $this->records->transaction(function () use ($candidate): array {
                $runId = (string) ($candidate['run_id'] ?? '');
                $this->records->lock('tool-report:'.$runId);
                $request = $this->records->find('tool_report_requests', $candidate['id']);
                if (! $request) {
                    return [];
                }
                $removed = [];
                $requestExpired = $this->expired($request['expires_at'] ?? null);
                $run = $runId !== '' ? $this->records->find('tool_runs', $runId) : null;
                $deleteRequest = ! $run || $this->expired($run['expires_at'] ?? null) || ($requestExpired && $request['status'] === 'pending');
                $tokenId = (string) ($request['token_hash'] ?? '');
                if (($requestExpired || $deleteRequest) && $tokenId !== '' && $this->records->find('tool_report_tokens', $tokenId)) {
                    $this->records->delete('tool_report_tokens', $tokenId);
                    $removed[] = 'tool_report_tokens';
                }
                if ($deleteRequest) {
                    $this->records->delete('tool_report_requests', $request['id']);
                    $removed[] = 'tool_report_requests';
                }

                return $removed;
            });
            foreach ($removed as $collection) {
                $deleted[$collection]++;
            }
        }
        foreach ($this->records->all('tool_report_tokens') as $token) {
            $requestId = (string) ($token['request_id'] ?? '');
            if ($requestId === '' || ! $this->records->find('tool_report_requests', $requestId)) {
                $this->records->delete('tool_report_tokens', $token['id']);
                $deleted['tool_report_tokens']++;
            }
        }
        foreach ($this->records->all('tool_runs') as $candidate) {
            if (! $this->expired($candidate['expires_at'] ?? null)) {
                continue;
            }
            $deleted['tool_runs'] += $this->records->transaction(function () use ($candidate): int {
                $this->records->lock('tool-generation:'.$candidate['id']);
                $run = $this->records->find('tool_runs', $candidate['id']);
                if (! $run || ! $this->expired($run['expires_at'] ?? null)) {
                    return 0;
                }
                $this->records->delete('tool_runs', $run['id']);

                return 1;
            });
        }

        return $deleted;
    }

    private function expired(?string $timestamp): bool
    {
        return $timestamp !== null && $timestamp !== '' && Carbon::parse($timestamp)->lte(now());
    }

    private function publicResult(array $run): array
    {
        return ['id' => $run['id'], 'tool' => $run['tool'], 'status' => $run['status'], 'result' => $run['result']];
    }
}

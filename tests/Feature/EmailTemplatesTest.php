<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace Tests\Feature;

use App\Communications\EmailTemplates;
use App\Contracts\RecordRepository;
use App\Identity\CmsUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class EmailTemplatesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cms.mfa_required' => false]);
        if (! Route::has('email-templates.index')) {
            Route::middleware('web')->group(base_path('routes/templates.php'));
        }
    }

    public function test_owner_can_edit_templates_with_versions_and_revision_history(): void
    {
        $this->owner();
        $this->getJson('/api/v1/email-templates')->assertOk()->assertJsonCount(11, 'data');
        $updated = $this->putJson('/api/v1/email-templates/invoice', ['subject' => 'Your invoice {{ document_number }}', 'body' => 'Hello {{ recipient_name }}. Thank you.', 'expected_version' => 0])->assertOk()->json('data');
        $this->assertSame(1, $updated['version']);
        $this->putJson('/api/v1/email-templates/invoice', ['subject' => 'Stale title', 'body' => 'Stale body', 'expected_version' => 0])->assertConflict();
        $this->getJson('/api/v1/email-templates/invoice/revisions')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.template_version', 0);
    }

    public function test_queued_email_snapshot_does_not_change_after_template_edit(): void
    {
        $templates = app(EmailTemplates::class);
        $payload = EmailTemplates::snapshot(['to' => 'pat@example.test', 'template_key' => 'invoice', 'template_data' => ['recipient_name' => 'Pat', 'document_number' => 'INV-100', 'total' => 'USD 125.00', 'action_url' => 'https://contractor.example/app/invoices']]);
        $record = app(RecordRepository::class)->create('outbox', ['type' => 'email', 'payload' => $payload, 'status' => 'pending']);
        $templates->update('invoice', ['subject' => 'A different subject', 'body' => 'Changed delivery text.', 'expected_version' => 0]);
        $frozen = app(RecordRepository::class)->get('outbox', $record['id'])['payload'];
        $this->assertSame(0, $frozen['template_version']);
        $this->assertStringContainsString('INV-100', $frozen['subject']);
        $this->assertStringNotContainsString('Changed delivery text', $frozen['body']);
        $this->assertArrayNotHasKey('template_data', $frozen);
    }

    public function test_required_action_links_are_retained_when_template_omits_placeholder(): void
    {
        $templates = app(EmailTemplates::class);
        $templates->update('password_reset', ['subject' => 'Password help', 'body' => 'Hello {{ recipient_name }}.', 'expected_version' => 0]);
        $message = $templates->render('password_reset', ['recipient_name' => 'Pat', 'action_url' => 'https://contractor.example/reset-password/private-token']);
        $this->assertStringContainsString('https://contractor.example/reset-password/private-token', $message['body']);
        $this->assertSame(1, $message['template_version']);
    }

    public function test_template_expressions_unknown_variables_and_subject_header_injection_are_rejected(): void
    {
        $this->owner();
        foreach (['{{ user.password }}', '{{ api_key }}', '{% for name in users %}{{ name }}{% endfor %}'] as $body) {
            $this->putJson('/api/v1/email-templates/message', ['subject' => 'Hello', 'body' => $body, 'expected_version' => 0])->assertUnprocessable();
        }
        $this->putJson('/api/v1/email-templates/message', ['subject' => "Hello\r\nBcc: attacker@example.test", 'body' => 'Hello', 'expected_version' => 0])->assertUnprocessable();
    }

    public function test_invalid_action_url_cannot_remove_an_account_link(): void
    {
        $this->expectException(ValidationException::class);
        app(EmailTemplates::class)->render('invitation', ['action_url' => 'javascript:alert(1)']);
    }

    public function test_subject_values_are_plain_text_and_cannot_add_headers(): void
    {
        $templates = app(EmailTemplates::class);
        $templates->update('message', ['subject' => 'Hello {{ recipient_name }}', 'body' => '{{ message }}', 'expected_version' => 0]);
        $output = $templates->render('message', ['recipient_name' => "Pat\r\nBcc: other@example.test", 'message' => '{{ api_key }}']);
        $this->assertStringNotContainsString("\n", $output['subject']);
        $this->assertSame('{{ api_key }}', $output['body']);
    }

    public function test_non_admin_cannot_read_or_edit_templates(): void
    {
        $this->actingAs(new CmsUser(['id' => 'marketing', 'name' => 'Marketing', 'email' => 'marketing@example.test', 'role' => 'marketing']));
        $this->getJson('/api/v1/email-templates')->assertForbidden();
        $this->putJson('/api/v1/email-templates/message', ['subject' => 'Hello', 'body' => 'Changed', 'expected_version' => 0])->assertForbidden();
    }

    public function test_preview_sends_no_message_and_legacy_payloads_remain_usable(): void
    {
        $this->owner();
        $this->postJson('/api/v1/email-templates/invitation/preview', [])->assertOk()->assertJsonPath('data.template_key', 'invitation');
        $this->assertCount(0, app(RecordRepository::class)->all('outbox'));
        $legacy = EmailTemplates::snapshot(['to' => 'pat@example.test', 'subject' => 'Hello', 'text' => 'A plain message']);
        $this->assertSame('A plain message', $legacy['body']);
    }

    private function owner(): void
    {
        $this->actingAs(new CmsUser(['id' => 'owner', 'name' => 'Owner', 'email' => 'owner@example.test', 'role' => 'owner']));
    }
}

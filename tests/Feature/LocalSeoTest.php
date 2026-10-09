<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace Tests\Feature;

use App\Contracts\RecordRepository;
use App\Identity\CmsUser;
use App\Publishing\WebsiteSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocalSeoTest extends TestCase
{
    use RefreshDatabase;

    private RecordRepository $records;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cms.data_provider' => 'postgres', 'cms.mfa_required' => false, 'app.url' => 'https://contractor.example']);
        $this->records = app(RecordRepository::class);
        $this->actingAs(new CmsUser(['id' => 'marketing', 'name' => 'Marketing', 'email' => 'marketing@example.test', 'role' => 'marketing']));
        $website = app(WebsiteSettings::class);
        $settings = $website->get();
        $draft = $settings['draft'];
        $draft['profile'] = array_replace($draft['profile'], ['name' => 'Acme Plumbing', 'phone' => '+1 (416) 555-0100', 'address_visibility' => 'visible', 'address' => '12 Main Street', 'city' => 'Toronto', 'region' => 'ON', 'postal_code' => 'M5V 1A1', 'country' => 'CA']);
        $saved = $website->update($draft, $settings['version'], 'marketing');
        $website->publish($saved['version'], 'marketing');
    }

    public function test_manual_observations_normalize_formatting_without_claiming_automatic_verification(): void
    {
        $data = $this->postJson('/api/v1/website/citations', $this->observation())->assertCreated()->json('data');
        $this->assertSame('matches', $data['comparison']['status']);
        $this->assertSame('manual', $data['provenance']);
        $this->assertSame('marketing', $data['checked_by']);
        $this->assertFalse($data['stale']);
        $this->assertSame('12 Main St., Toronto, ON, M5V 1A1, Canada', $data['observed_address']);
        $this->getJson('/api/v1/website/citations')->assertOk()->assertJsonPath('data.0.comparison.status', 'matches');
    }

    public function test_missing_evidence_is_unchecked_and_differences_name_the_specific_fields(): void
    {
        $unchecked = $this->observation();
        unset($unchecked['observed_phone']);
        $this->postJson('/api/v1/website/citations', $unchecked)->assertCreated()->assertJsonPath('data.comparison.status', 'unchecked')->assertJsonPath('data.comparison.fields.phone', 'unchecked');
        $different = $this->observation();
        $different['observed_phone'] = '416 555 9999';
        $this->postJson('/api/v1/website/citations', $different)->assertCreated()->assertJsonPath('data.comparison.status', 'difference')->assertJsonPath('data.comparison.differing_fields.0', 'phone');
        $unchecked['checked_at'] = null;
        $this->postJson('/api/v1/website/citations', $unchecked)->assertCreated()->assertJsonPath('data.comparison.fields.name', 'unchecked');
    }

    public function test_changes_to_public_revision_make_older_citations_stale_and_correction_only_edits_do_not_reverify(): void
    {
        $observation = $this->observation();
        $row = $this->postJson('/api/v1/website/citations', $observation)->assertCreated()->json('data');
        $website = app(WebsiteSettings::class);
        $settings = $website->get();
        $draft = $settings['draft'];
        $draft['profile']['name'] = 'Acme new public name';
        $settings = $website->update($draft, $settings['version'], 'marketing');
        $this->getJson('/api/v1/website/citations')->assertOk()->assertJsonPath('data.0.stale', false);
        $website->publish($settings['version'], 'marketing');
        $this->getJson('/api/v1/website/citations')->assertOk()->assertJsonPath('data.0.comparison.status', 'stale');
        $observation['correction_status'] = 'submitted';
        $this->putJson('/api/v1/website/citations/'.$row['id'], $observation + ['expected_version' => $row['version']])->assertOk()->assertJsonPath('data.stale', true)->assertJsonPath('data.canonical_revision', $row['canonical_revision']);
        $this->putJson('/api/v1/website/citations/'.$row['id'], $observation + ['expected_version' => $row['version']])->assertConflict();
    }

    public function test_old_observations_and_hidden_address_exposure_are_flagged_for_review(): void
    {
        $old = $this->observation();
        $old['checked_at'] = now()->subDays(91)->toIso8601String();
        $this->postJson('/api/v1/website/citations', $old)->assertCreated()->assertJsonPath('data.stale', true);
        $website = app(WebsiteSettings::class);
        $settings = $website->get();
        $draft = $settings['draft'];
        $draft['profile']['address_visibility'] = 'hidden';
        $settings = $website->update($draft, $settings['version'], 'marketing');
        $website->publish($settings['version'], 'marketing');
        $this->postJson('/api/v1/website/citations', $this->observation())->assertCreated()->assertJsonPath('data.comparison.status', 'needs_review')->assertJsonPath('data.comparison.fields.address', 'needs_review');
        $this->getJson('/api/v1/website/local-seo')->assertOk()->assertDontSee('12 Main Street');
    }

    public function test_csv_neutralizes_formulas_and_keeps_canonical_private_addresses_out(): void
    {
        $observation = $this->observation();
        $observation['provider'] = '=HYPERLINK("https://evil.test")';
        $observation['notes'] = ' @SUM(1+1)';
        $this->postJson('/api/v1/website/citations', $observation)->assertCreated();
        $response = $this->get('/api/v1/website/citations/export')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $response->getContent());
        rewind($stream);
        fgetcsv($stream, 0, ',', '"', '');
        $row = fgetcsv($stream, 0, ',', '"', '');
        fclose($stream);
        $this->assertStringStartsWith("'=", $row[1]);
        $this->assertStringStartsWith("'@", $row[16]);
        $this->assertSame('manual', $row[12]);
    }

    public function test_nap_export_uses_published_public_identity_and_redacts_hidden_addresses(): void
    {
        $website = app(WebsiteSettings::class);
        $settings = $website->get();
        $settings['draft']['profile']['address_visibility'] = 'hidden';
        $settings['draft']['profile']['name'] = '=Public contractor';
        $settings = $website->update($settings['draft'], $settings['version'], 'marketing');
        $settings = $website->publish($settings['version'], 'marketing');
        $settings['draft']['profile']['name'] = 'DRAFT ONLY NAME';
        $website->update($settings['draft'], $settings['version'], 'marketing');
        $csv = $this->get('/api/v1/website/nap-export')->assertOk()->getContent();
        $this->assertStringNotContainsString('12 Main Street', $csv);
        $this->assertStringNotContainsString('M5V 1A1', $csv);
        $this->assertStringNotContainsString('DRAFT ONLY NAME', $csv);
        $this->assertStringContainsString("'=Public contractor", $csv);
    }

    public function test_coverage_counts_real_pages_and_diagnostics_find_low_contrast(): void
    {
        $draft = $this->postJson('/api/v1/pages', ['title' => 'Service', 'slug' => 'service', 'type' => 'service'])->assertCreated()->json('data');
        $this->getJson('/api/v1/website/local-seo')->assertOk()->assertJsonPath('data.counts.draft_pages', 1)->assertJsonPath('data.counts.published_pages', 0);
        $this->postJson('/api/v1/pages/'.$draft['id'].'/publish', ['expected_version' => $draft['version']])->assertOk();
        $website = app(WebsiteSettings::class);
        $settings = $website->get();
        $settings['draft']['appearance']['colors']['text'] = '#FAFAF7';
        $settings = $website->update($settings['draft'], $settings['version'], 'marketing');
        $website->publish($settings['version'], 'marketing');
        $report = $this->getJson('/api/v1/website/local-seo')->assertOk()->assertJsonPath('data.counts.published_pages', 1)->json('data');
        $service = collect($report['coverage'])->firstWhere('type', 'service');
        $this->assertSame(1, $service['published_count']);
        $this->assertSame('/app/pages?edit='.$draft['id'], $service['pages'][0]['edit_url']);
        $this->assertTrue(collect($report['diagnostics'])->contains('code', 'low_contrast'));
    }

    public function test_future_checks_unknown_locations_and_credential_urls_are_rejected(): void
    {
        foreach ([['checked_at', now()->addDay()->toIso8601String()], ['location_id', 'fake-office'], ['listing_url', 'https://user:secret@example.test'], ['correction_owner', 'nonexistent']] as [$field, $value]) {
            $input = $this->observation();
            $input[$field] = $value;
            $this->postJson('/api/v1/website/citations', $input)->assertUnprocessable();
        }
    }

    public function test_diagnostics_link_to_existing_profile_and_branch_editors(): void
    {
        $website = app(WebsiteSettings::class);
        $settings = $website->get();
        $settings['draft']['locations'] = [array_replace($settings['draft']['profile'], ['id' => 'north', 'name' => 'North branch', 'phone' => '', 'address_visibility' => 'hidden'])];
        $settings = $website->update($settings['draft'], $settings['version'], 'marketing');
        $website->publish($settings['version'], 'marketing');
        $issues = $this->getJson('/api/v1/website/local-seo')->assertOk()->json('data.diagnostics');
        $this->assertTrue(collect($issues)->contains('path', '/app/website/local-seo/business-profile'));
        $this->assertTrue(collect($issues)->contains('path', '/app/website/local-seo/locations/north'));
        $this->assertSame('/app/website/local-seo/locations/north', collect($issues)->firstWhere('code', 'branch_page_missing')['path']);
        foreach (array_unique(array_column($issues, 'path')) as $path) {
            $this->assertNotSame('/app/website/profile', $path);
            $this->get($path)->assertOk();
        }
    }

    public function test_primary_button_contrast_uses_its_actual_white_foreground(): void
    {
        $website = app(WebsiteSettings::class);
        $settings = $website->get();
        $settings['draft']['appearance']['colors'] = array_replace($settings['draft']['appearance']['colors'], ['background' => '#000000', 'text' => '#FFFFFF', 'muted' => '#FFFFFF', 'primary' => '#777777']);
        $settings = $website->update($settings['draft'], $settings['version'], 'marketing');
        $website->publish($settings['version'], 'marketing');
        $issues = $this->getJson('/api/v1/website/local-seo')->assertOk()->json('data.diagnostics');
        $contrast = collect($issues)->where('code', 'low_contrast')->values();
        $this->assertCount(1, $contrast);
        $this->assertStringStartsWith('Primary buttons contrast is 4.48:1.', $contrast[0]['message']);
        $this->assertSame('/app/website/appearance', $contrast[0]['path']);
    }

    public function test_object_key_order_does_not_change_manual_verification_or_address_display_order(): void
    {
        $input = $this->observation();
        $row = $this->postJson('/api/v1/website/citations', $input)->assertCreated()->json('data');
        $website = app(WebsiteSettings::class);
        $settings = $website->get();
        $settings['draft']['profile'] = array_reverse($settings['draft']['profile'], true);
        $settings['draft']['appearance']['colors']['accent'] = '#ABCDEF';
        $settings = $website->update($settings['draft'], $settings['version'], 'marketing');
        $website->publish($settings['version'], 'marketing');
        $input = array_reverse($input, true);
        $input['correction_status'] = 'submitted';
        $updated = $this->putJson('/api/v1/website/citations/'.$row['id'], $input + ['expected_version' => $row['version']])->assertOk()->json('data');
        $this->assertTrue($updated['stale']);
        $this->assertSame($row['canonical_revision'], $updated['canonical_revision']);
        $this->assertSame($row['checked_by'], $updated['checked_by']);
        $this->assertSame('matches', $updated['comparison']['fields']['address']);
        $csv = $this->get('/api/v1/website/nap-export')->assertOk()->getContent();
        $this->assertStringContainsString('12 Main Street, Toronto, ON, M5V 1A1, CA', $csv);
    }

    private function observation(): array
    {
        return ['location_id' => 'primary', 'provider' => 'Business directory', 'listing_url' => 'https://directory.example/acme', 'observed_name' => 'ACME Plumbing', 'observed_address' => '12 Main St., Toronto, ON, M5V 1A1, Canada', 'observed_phone' => '416-555-0100', 'observed_website' => 'https://www.contractor.example/', 'checked_at' => now()->subMinute()->toIso8601String(), 'correction_status' => 'none'];
    }
}

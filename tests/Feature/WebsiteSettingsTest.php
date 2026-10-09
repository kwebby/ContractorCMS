<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace Tests\Feature;

use App\Contracts\RecordRepository;
use App\Identity\CmsUser;
use App\Publishing\WebsiteSettings;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class WebsiteSettingsTest extends TestCase
{
    use RefreshDatabase;

    private RecordRepository $records;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cms.data_provider' => 'postgres', 'cms.mfa_required' => false]);
        $this->records = app(RecordRepository::class);
        $this->staff();
    }

    public function test_website_editing_is_for_publishing_staff_and_does_not_expose_billing_settings(): void
    {
        Settings::put('business', ['name' => 'Public contractor', 'legal_name' => 'Private legal entity', 'phone' => '+1 416 555 0100', 'address' => 'Secret residence', 'city' => 'Private city', 'tax_id' => 'Private tax ID', 'country' => 'CA', 'timezone' => 'America/Toronto']);
        $website = $this->getJson('/api/v1/website')->assertOk()->assertDontSee('Secret residence')->assertDontSee('Private legal entity')->assertDontSee('Private tax ID')->json('data');
        $this->assertSame('hidden', $website['draft']['profile']['address_visibility']);
        $this->assertSame('Public contractor', $website['draft']['profile']['name']);
        $this->assertSame('CA', $website['draft']['profile']['country']);
        $this->assertSame('America/Toronto', $website['draft']['profile']['timezone']);
        $this->staff('marketing');
        $website['draft']['profile']['name'] = 'New public name';
        $this->putJson('/api/v1/website', ['expected_version' => $website['version'], 'draft' => $website['draft']])->assertOk();
        $this->assertSame('Public contractor', Settings::get('business')['name']);
        foreach (['client', 'technician', 'office', 'finance'] as $role) {
            $this->staff($role);
            foreach (['', '/revisions', '/local-seo', '/nap-export', '/citations', '/citations/export', '/fonts', '/presets'] as $path) {
                $this->getJson('/api/v1/website'.$path)->assertForbidden();
            }
            $this->putJson('/api/v1/website', ['expected_version' => 1, 'draft' => []])->assertForbidden();
            $this->postJson('/api/v1/website/publish', ['expected_version' => 1])->assertForbidden();
        }
        Auth::forgetGuards();
        $this->getJson('/api/v1/website')->assertUnauthorized();
    }

    public function test_drafts_snapshots_conflicts_and_restore_preserve_the_live_public_profile(): void
    {
        $website = $this->website();
        $website['draft']['profile']['name'] = 'Published name';
        $website = $this->save($website);
        $website = $this->publish($website);
        $publishedVersion = $website['published_version'];
        $website['draft']['profile']['name'] = 'Draft name';
        $website = $this->save($website);
        $this->assertSame('Published name', app(WebsiteSettings::class)->business()['name']);
        $this->assertSame('Draft name', app(WebsiteSettings::class)->business(true)['name']);
        $this->assertTrue($website['has_unpublished_changes']);
        $this->assertSame($publishedVersion, $website['published_version']);
        $this->postJson('/api/v1/website/publish', ['expected_version' => $website['version'] - 1])->assertConflict();
        $revisions = $this->getJson('/api/v1/website/revisions')->assertOk()->json('data');
        $revision = collect($revisions)->first(fn (array $row): bool => $row['snapshot']['profile']['name'] === 'Published name');
        $restored = $this->postJson('/api/v1/website/revisions/'.$revision['id'].'/restore', ['expected_version' => $website['version']])->assertOk()->json('data');
        $this->assertSame('Published name', $restored['draft']['profile']['name']);
        $this->assertSame($website['published'], $restored['published']);
        $this->assertSame($publishedVersion, $restored['published_version']);
    }

    public function test_hidden_addresses_are_removed_from_every_public_projection_including_branches(): void
    {
        $website = $this->website();
        $website['draft']['profile'] = array_replace($website['draft']['profile'], ['name' => 'Home based roofer', 'address' => 'PRIVATE 123 Street', 'city' => 'PRIVATE city', 'region' => 'PRIVATE region', 'postal_code' => 'PRIVATE ZIP']);
        $website['draft']['locations'] = [$website['draft']['profile'] + ['id' => 'north', 'page_id' => null]];
        $website = $this->publish($this->save($website));
        foreach ([app(WebsiteSettings::class)->publicData(), app(WebsiteSettings::class)->publicData(true), app(WebsiteSettings::class)->business(false, 'north')] as $projection) {
            $this->assertStringNotContainsString('PRIVATE', json_encode($projection));
        }
        $this->assertSame('north', app(WebsiteSettings::class)->business(false, 'north')['location_id']);
        $this->assertSame('PRIVATE 123 Street', $website['draft']['locations'][0]['address']);
        $this->getJson('/api/v1/website/local-seo')->assertOk()->assertDontSee('PRIVATE');
    }

    public function test_only_safe_typed_values_and_local_publishing_media_are_accepted(): void
    {
        $website = $this->website();
        $cases = [
            ['appearance.colors.primary', '#fff;display:none'], ['appearance.heading_font_id', 'url(https://evil.test/font)'],
            ['profile.name', '<img src=x onerror=alert(1)>'], ['profile.latitude', 43.0],
            ['navigation.primary', [['label' => 'Bad', 'kind' => 'url', 'url' => 'javascript:alert(1)']]],
            ['navigation.primary', [['label' => 'Bad', 'kind' => 'url', 'url' => 'https://user:password@example.test/']]],
            ['navigation.primary', [['label' => 'Bad', 'kind' => 'url', 'url' => '//example.test/']]],
            ['profile.phone', '555'], ['profile.hours', [['day' => 'Monday', 'opens' => '09:00', 'closed' => false]]],
        ];
        foreach ($cases as [$path, $value]) {
            $draft = $website['draft'];
            data_set($draft, $path, $value);
            $this->putJson('/api/v1/website', ['expected_version' => $website['version'], 'draft' => $draft])->assertUnprocessable();
        }
        $private = $this->records->create('files', ['visibility' => 'private', 'initial_visibility' => 'private', 'mime' => 'image/png', 'status' => 'ready']);
        $website['draft']['appearance']['logo_media_id'] = $private['id'];
        $this->putJson('/api/v1/website', ['expected_version' => $website['version'], 'draft' => $website['draft']])->assertUnprocessable();
        $this->assertSame(1, $this->website()['version']);
    }

    public function test_draft_branding_is_promoted_only_by_successful_website_publication(): void
    {
        $file = $this->records->create('files', ['visibility' => 'draft', 'initial_visibility' => 'draft', 'mime' => 'image/png', 'status' => 'ready']);
        $website = $this->website();
        $website['draft']['profile']['name'] = 'Safe brand';
        $website['draft']['appearance']['logo_media_id'] = $file['id'];
        $website = $this->save($website);
        $this->assertSame('draft', $this->records->get('files', $file['id'])['visibility']);
        $this->postJson('/api/v1/website/publish', ['expected_version' => $website['version'] - 1])->assertConflict();
        $this->assertSame('draft', $this->records->get('files', $file['id'])['visibility']);
        $this->publish($website);
        $this->assertSame('public', $this->records->get('files', $file['id'])['visibility']);
    }

    public function test_managed_links_require_published_targets_and_branch_associations_are_typed(): void
    {
        $page = $this->postJson('/api/v1/pages', ['title' => 'Contact', 'slug' => 'contact', 'type' => 'contact'])->assertCreated()->json('data');
        $website = $this->website();
        $website['draft']['profile']['name'] = 'Actual business';
        $website['draft']['navigation']['primary'] = [['label' => 'Contact', 'kind' => 'page', 'page_id' => $page['id']]];
        $website = $this->save($website);
        $this->postJson('/api/v1/website/publish', ['expected_version' => $website['version']])->assertUnprocessable();
        $this->postJson('/api/v1/pages/'.$page['id'].'/publish', ['expected_version' => $page['version']])->assertOk();
        $website = $this->publish($website);
        $website['draft']['locations'] = [$website['draft']['profile'] + ['id' => 'branch', 'page_id' => $page['id']]];
        $this->putJson('/api/v1/website', ['expected_version' => $website['version'], 'draft' => $website['draft']])->assertUnprocessable();
    }

    public function test_profiles_reject_duplicate_hours_areas_and_location_ids(): void
    {
        $website = $this->website();
        $hours = ['day' => 'Monday', 'opens' => '09:00', 'closes' => '17:00', 'closed' => false];
        foreach ([['profile.hours', [$hours, $hours]], ['profile.service_areas', ['Toronto', 'toronto']], ['locations', [$website['draft']['profile'] + ['id' => 'west'], $website['draft']['profile'] + ['id' => 'west']]]] as [$path, $value]) {
            $draft = $website['draft'];
            data_set($draft, $path, $value);
            $this->putJson('/api/v1/website', ['expected_version' => $website['version'], 'draft' => $draft])->assertUnprocessable();
        }
    }

    public function test_a_branch_page_cannot_be_associated_with_multiple_locations_or_a_conflicting_revision(): void
    {
        $page = $this->postJson('/api/v1/pages', ['title' => 'Branch', 'slug' => 'branch', 'type' => 'branch'])->assertCreated()->json('data');
        $page = $this->postJson('/api/v1/pages/'.$page['id'].'/publish', ['expected_version' => $page['version']])->assertOk()->json('data');
        $website = $this->website();
        $website['draft']['profile']['name'] = 'Actual company';
        $location = $website['draft']['profile'] + ['id' => 'north', 'page_id' => $page['id']];
        $website['draft']['locations'] = [$location, array_replace($location, ['id' => 'south'])];
        $this->putJson('/api/v1/website', ['expected_version' => $website['version'], 'draft' => $website['draft']])->assertUnprocessable()->assertJsonValidationErrors('draft.locations.1.page_id');
        $website['draft']['locations'] = [$location];
        $this->records->update('pages', $page['id'], ['location_id' => 'south']);
        $this->putJson('/api/v1/website', ['expected_version' => $website['version'], 'draft' => $website['draft']])->assertUnprocessable()->assertJsonValidationErrors('draft.locations.0.page_id');
        $this->records->update('pages', $page['id'], ['location_id' => null, 'published' => array_replace($page['published'], ['location_id' => 'south'])]);
        $this->putJson('/api/v1/website', ['expected_version' => $website['version'], 'draft' => $website['draft']])->assertUnprocessable()->assertJsonValidationErrors('draft.locations.0.page_id');
        $this->records->update('pages', $page['id'], ['published' => $page['published']]);
        $this->publish($this->save($website));
    }

    public function test_explicit_branch_page_associations_require_valid_draft_and_published_locations(): void
    {
        $input = ['title' => 'North branch', 'slug' => 'north', 'type' => 'branch', 'location_id' => 'north'];
        $this->postJson('/api/v1/pages', $input)->assertUnprocessable()->assertJsonValidationErrors('location_id');
        $website = $this->website();
        $website['draft']['profile']['name'] = 'Company';
        $website['draft']['locations'] = [$website['draft']['profile'] + ['id' => 'north']];
        $website = $this->save($website);
        $page = $this->postJson('/api/v1/pages', $input)->assertCreated()->json('data');
        $this->postJson('/api/v1/pages/'.$page['id'].'/publish', ['expected_version' => $page['version']])->assertUnprocessable()->assertJsonValidationErrors('location_id');
        $website = $this->publish($website);
        $page = $this->postJson('/api/v1/pages/'.$page['id'].'/publish', ['expected_version' => $page['version']])->assertOk()->json('data');
        $this->postJson('/api/v1/pages', array_replace($input, ['slug' => 'duplicate-north']))->assertUnprocessable()->assertJsonValidationErrors('location_id');
        $this->putJson('/api/v1/pages/'.$page['id'], ['expected_version' => $page['version'], 'location_id' => 'unknown'])->assertUnprocessable()->assertJsonValidationErrors('location_id');
        $website['draft']['locations'][0]['page_id'] = $page['id'];
        $website = $this->publish($this->save($website));
        $this->putJson('/api/v1/pages/'.$page['id'], ['expected_version' => $page['version'], 'location_id' => null, 'type' => 'service-area'])->assertUnprocessable()->assertJsonValidationErrors('location_id');
        $website['draft']['locations'] = [];
        $this->publish($this->save($website));
        $this->assertSame(['location_id' => null], app(WebsiteSettings::class)->business(false, 'north'));
        $this->postJson('/api/v1/pages/'.$page['id'].'/publish', ['expected_version' => $page['version']])->assertUnprocessable()->assertJsonValidationErrors('location_id');
        $diagnostics = $this->getJson('/api/v1/website/local-seo')->assertOk()->json('data.diagnostics');
        $this->assertTrue(collect($diagnostics)->contains('code', 'branch_profile_missing'));
    }

    public function test_header_anchors_can_be_drafted_but_must_target_enabled_published_sections_to_publish(): void
    {
        $website = $this->website();
        $website['draft']['profile']['name'] = 'Actual business';
        $website['draft']['navigation']['header_button'] = ['label' => 'Learn more', 'kind' => 'anchor', 'anchor' => 'missing-section', 'style' => 'primary'];
        $website = $this->save($website);
        $this->postJson('/api/v1/website/publish', ['expected_version' => $website['version']])->assertUnprocessable()->assertJsonValidationErrors('draft.navigation.header_button.anchor');
        $website['draft']['navigation']['header_button']['anchor'] = 'contact';
        $website = $this->publish($this->save($website));
        $sections = [];
        foreach (['hero' => true, 'about' => false, 'contact' => false] as $type => $enabled) {
            $sections[] = ['id' => $type.'-section', 'type' => $type, 'enabled' => $enabled, 'variant' => 'standard', 'heading' => ucfirst($type), 'body' => [], 'buttons' => [], 'items' => []];
        }
        $page = $this->postJson('/api/v1/pages', ['title' => 'Home', 'slug' => 'home', 'type' => 'home', 'home_sections' => ['schema_version' => 1, 'mode' => 'guided', 'sections' => $sections]])->assertCreated()->json('data');
        $this->postJson('/api/v1/pages/'.$page['id'].'/publish', ['expected_version' => $page['version']])->assertOk();
        foreach (['about-section', 'contact', 'request-service', 'unknown'] as $anchor) {
            $website['draft']['navigation']['header_button']['anchor'] = $anchor;
            $website = $this->save($website);
            $this->postJson('/api/v1/website/publish', ['expected_version' => $website['version']])->assertUnprocessable()->assertJsonValidationErrors('draft.navigation.header_button.anchor');
        }
        $website['draft']['navigation']['header_button']['anchor'] = 'hero-section';
        $this->publish($this->save($website));
    }

    public function test_reordered_object_keys_do_not_mark_settings_dirty_but_ordered_lists_still_do(): void
    {
        $website = $this->website();
        $website['draft']['profile']['name'] = 'Actual contractor';
        $website['draft']['profile']['service_areas'] = ['Toronto', 'Ottawa'];
        $website['draft']['navigation']['primary'] = [['label' => 'First', 'kind' => 'url', 'url' => '/first'], ['label' => 'Second', 'kind' => 'url', 'url' => '/second']];
        $website = $this->publish($this->save($website));
        $website['draft'] = array_reverse($website['draft'], true);
        $website['draft']['profile'] = array_reverse($website['draft']['profile'], true);
        $website['draft']['appearance']['colors'] = array_reverse($website['draft']['appearance']['colors'], true);
        $website['draft']['navigation']['primary'][0] = array_reverse($website['draft']['navigation']['primary'][0], true);
        $website = $this->save($website);
        $this->assertFalse($website['has_unpublished_changes']);
        $website['draft']['navigation']['primary'] = array_reverse($website['draft']['navigation']['primary']);
        $website = $this->save($website);
        $this->assertTrue($website['has_unpublished_changes']);
        $website['draft']['navigation']['primary'] = array_reverse($website['draft']['navigation']['primary']);
        $website['draft']['profile']['service_areas'] = array_reverse($website['draft']['profile']['service_areas']);
        $website = $this->save($website);
        $this->assertTrue($website['has_unpublished_changes']);
        $website['draft']['profile']['service_areas'] = array_reverse($website['draft']['profile']['service_areas']);
        $website = $this->save($website);
        $this->assertFalse($website['has_unpublished_changes']);
    }

    private function website(): array
    {
        return $this->getJson('/api/v1/website')->assertOk()->json('data');
    }

    private function save(array $website): array
    {
        return $this->putJson('/api/v1/website', ['expected_version' => $website['version'], 'draft' => $website['draft']])->assertOk()->json('data');
    }

    private function publish(array $website): array
    {
        return $this->postJson('/api/v1/website/publish', ['expected_version' => $website['version']])->assertOk()->json('data');
    }

    private function staff(string $role = 'owner'): void
    {
        $this->actingAs(new CmsUser(['id' => $role, 'name' => ucfirst($role), 'email' => $role.'@example.test', 'role' => $role]));
    }
}

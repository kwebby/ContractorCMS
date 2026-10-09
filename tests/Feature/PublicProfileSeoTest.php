<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace Tests\Feature;

use App\Contracts\RecordRepository;
use App\Identity\CmsUser;
use App\Publishing\SeoBuilder;
use App\Publishing\WebsiteSettings;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PublicProfileSeoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cms.data_provider' => 'postgres', 'cms.mfa_required' => false, 'app.url' => 'https://contractor.example']);
    }

    public function test_schema_trade_identifiers_and_plain_language_aliases_map_to_the_real_business_type(): void
    {
        foreach (['Plumber' => 'Plumber', 'plumbing' => 'Plumber', 'Electrician' => 'Electrician', 'electrical' => 'Electrician', 'HVACBusiness' => 'HVACBusiness', 'hvac' => 'HVACBusiness', 'RoofingContractor' => 'RoofingContractor', 'roofing' => 'RoofingContractor', 'GeneralContractor' => 'GeneralContractor', 'HomeAndConstructionBusiness' => 'HomeAndConstructionBusiness'] as $trade => $expected) {
            $graph = $this->graph($this->page(), ['name' => 'Actual business', 'trade' => $trade]);
            $this->assertSame($expected, $graph[0]['@type']);
        }
    }

    public function test_hidden_address_never_creates_postal_address_or_coordinate_output_even_with_country_present(): void
    {
        foreach ([['address_visibility' => 'hidden', 'country' => 'CA'], ['address_visibility' => 'hidden', 'country' => 'CA', 'address' => 'PRIVATE HOME', 'city' => 'PRIVATE CITY', 'region' => 'PRIVATE REGION', 'postal_code' => 'PRIVATE ZIP', 'latitude' => '43.123456', 'longitude' => '-79.654321']] as $private) {
            $graph = $this->graph($this->page(), ['name' => 'Service area contractor', 'service_areas' => ['Toronto', 'Mississauga']] + $private);
            $encoded = json_encode($graph);
            $this->assertArrayNotHasKey('address', $graph[0]);
            $this->assertStringNotContainsString('PostalAddress', $encoded);
            $this->assertStringNotContainsString('PRIVATE', $encoded);
            $this->assertStringNotContainsString('43.123456', $encoded);
            $this->assertSame(['Toronto', 'Mississauga'], $graph[0]['areaServed']);
        }
    }

    public function test_public_hours_holiday_closures_profiles_and_local_logo_are_emitted_without_ratings(): void
    {
        $business = [
            'name' => 'Actual contractor', 'phone' => '+1 416 555 0100', 'address_visibility' => 'visible', 'address' => '12 Real Street', 'country' => 'CA',
            'hours' => [['day' => 'Monday', 'opens' => '08:00', 'closes' => '17:00', 'closed' => false], ['day' => 'Sunday', 'closed' => true]],
            'special_hours' => [['date' => '2026-12-25', 'closed' => true], ['date' => '2026-12-31', 'opens' => '08:00', 'closes' => '12:00', 'closed' => false]],
            'profile_urls' => [['label' => 'Profile', 'url' => 'https://profiles.example/actual'], ['label' => 'Duplicate', 'url' => 'https://profiles.example/actual'], ['label' => 'Unsafe', 'url' => 'javascript:alert(1)'], ['label' => 'Credentials', 'url' => 'https://user:secret@profiles.example/actual']],
            'logo_url' => '/files/public-logo', 'aggregateRating' => ['ratingValue' => 5],
        ];
        $graph = $this->graph($this->page(), $business);
        $entity = $graph[0];
        $this->assertSame('12 Real Street', $entity['address']['streetAddress']);
        $this->assertSame('https://schema.org/Monday', $entity['openingHoursSpecification'][0]['dayOfWeek']);
        $this->assertSame('08:00', $entity['openingHoursSpecification'][0]['opens']);
        $this->assertSame('00:00', $entity['openingHoursSpecification'][1]['opens']);
        $this->assertSame('00:00', $entity['openingHoursSpecification'][1]['closes']);
        $this->assertSame('2026-12-25', $entity['specialOpeningHoursSpecification'][0]['validFrom']);
        $this->assertSame('2026-12-25', $entity['specialOpeningHoursSpecification'][0]['validThrough']);
        $this->assertSame('12:00', $entity['specialOpeningHoursSpecification'][1]['closes']);
        $this->assertSame(['https://profiles.example/actual'], $entity['sameAs']);
        $this->assertSame('https://contractor.example/files/public-logo', $entity['logo']);
        $this->assertArrayNotHasKey('aggregateRating', $entity);
    }

    public function test_branches_keep_stable_entity_ids_and_the_primary_company_publishes_the_site(): void
    {
        $page = $this->page('branch');
        $business = ['name' => 'North branch', 'trade' => 'Electrician', 'location_id' => 'north-branch', 'phone' => '416-555-0102', 'address_visibility' => 'visible', 'address' => '22 Branch Street', 'primary_business' => ['name' => 'Main contractor', 'phone' => '416-555-0101', 'address_visibility' => 'hidden', 'country' => 'CA']];
        $graph = collect($this->graph($page, $business))->keyBy('@id');
        $this->assertSame('Main contractor', $graph['https://contractor.example/#business']['name']);
        $this->assertArrayNotHasKey('address', $graph['https://contractor.example/#business']);
        $branch = $graph['https://contractor.example/#location-north-branch'];
        $this->assertSame('North branch', $branch['name']);
        $this->assertSame('https://contractor.example/#business', $branch['parentOrganization']['@id']);
        $this->assertSame('https://contractor.example/p/actual-page', $branch['url']);
        $this->assertSame('https://contractor.example/#business', $graph['https://contractor.example/#website']['publisher']['@id']);
        $this->assertSame('https://contractor.example/#location-north-branch', $graph['https://contractor.example/p/actual-page#page']['mainEntity']['@id']);
        $page['slug'] = 'renamed-branch';
        $renamed = collect($this->graph($page, $business))->keyBy('@id');
        $this->assertTrue($renamed->has('https://contractor.example/#location-north-branch'));
        $this->assertSame('https://contractor.example/p/renamed-branch', $renamed['https://contractor.example/#location-north-branch']['url']);
    }

    public function test_service_area_pages_do_not_invent_locations_and_preview_suppresses_schema(): void
    {
        $page = $this->page('service-area') + ['service_area' => 'Toronto'];
        $business = ['name' => 'Actual contractor', 'address_visibility' => 'hidden', 'country' => 'CA'];
        $graph = $this->graph($page, $business);
        $this->assertCount(1, array_filter($graph, fn (array $node): bool => $node['@type'] === 'GeneralContractor'));
        $this->assertCount(0, array_filter($graph, fn (array $node): bool => isset($node['parentOrganization']) || isset($node['address'])));
        $service = collect($graph)->firstWhere('@type', 'Service');
        $this->assertSame('Toronto', $service['areaServed']);
        $this->assertSame('https://contractor.example/#business', $service['provider']['@id']);
        $metadata = (new SeoBuilder)->build($page, $business, [], true);
        $this->assertNull($metadata['schema']);
        $this->assertSame('noindex,nofollow', $metadata['robots']);
    }

    public function test_published_branch_render_uses_its_public_profile_and_its_primary_parent(): void
    {
        $this->actingAs(new CmsUser(['id' => 'owner', 'name' => 'Owner', 'email' => 'owner@example.test', 'role' => 'owner']));
        $page = $this->postJson('/api/v1/pages', ['title' => 'North branch', 'slug' => 'north-branch', 'type' => 'branch'])->assertCreated()->json('data');
        $this->postJson('/api/v1/pages/'.$page['id'].'/publish', ['expected_version' => $page['version']])->assertOk();
        $website = app(WebsiteSettings::class);
        $record = $website->get();
        $draft = $record['draft'];
        $draft['profile']['name'] = 'Main contractor';
        $draft['profile']['address'] = 'SECRET MAIN ADDRESS';
        $draft['locations'] = [array_replace($draft['profile'], ['id' => 'north', 'page_id' => $page['id'], 'name' => 'North branch', 'trade' => 'HVACBusiness', 'address_visibility' => 'visible', 'address' => '22 Branch Street', 'city' => 'Toronto', 'region' => 'ON', 'postal_code' => 'M5V 1A1', 'country' => 'CA'])];
        $record = $website->update($draft, $record['version'], 'owner');
        $website->publish($record['version'], 'owner');
        $html = $this->get('/p/north-branch')->assertOk()->assertDontSee('SECRET MAIN ADDRESS')->getContent();
        preg_match('~<script[^>]*type="application/ld[+]json"[^>]*>(.*?)</script>~s', $html, $matches);
        $this->assertArrayHasKey(1, $matches);
        $graph = collect(json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR)['@graph'])->keyBy('@id');
        $this->assertSame('Main contractor', $graph['https://contractor.example/#business']['name']);
        $this->assertSame('HVACBusiness', $graph['https://contractor.example/#location-north']['@type']);
        $this->assertSame('22 Branch Street', $graph['https://contractor.example/#location-north']['address']['streetAddress']);
    }

    public function test_legacy_public_address_inputs_remain_supported(): void
    {
        $graph = $this->graph($this->page(), ['legal_name' => 'Legacy public business', 'address' => '12 Published Street', 'country' => 'US']);
        $this->assertSame('Legacy public business', $graph[0]['name']);
        $this->assertSame('12 Published Street', $graph[0]['address']['streetAddress']);
    }

    public function test_public_lead_tools_never_expose_raw_billing_identity_before_website_setup(): void
    {
        Http::preventStrayRequests();
        Settings::put('business', ['name' => 'Public company', 'trade' => 'plumbing', 'phone' => '+1 416 555 0100', 'country' => 'CA', 'address' => 'PRIVATE BILLING ADDRESS', 'city' => 'PRIVATE BILLING CITY', 'region' => 'PRIVATE BILLING REGION', 'postal_code' => 'PRIVATE BILLING POSTAL', 'legal_name' => 'PRIVATE LEGAL NAME', 'tax_id' => 'PRIVATE TAX IDENTIFIER']);
        foreach (['service-preparation', 'project-scope', 'quote-comparison'] as $slug) {
            $this->get('/tools/'.$slug)->assertOk()->assertHeader('X-Robots-Tag', 'noindex,follow')
                ->assertSee('Public company')->assertDontSee('PRIVATE')->assertDontSee('PostalAddress')
                ->assertSee('/assets/website-fonts.css', false)->assertSee('currently unavailable');
        }
        Http::assertNothingSent();
    }

    public function test_lead_tools_use_published_profile_appearance_navigation_and_global_social_settings(): void
    {
        Http::preventStrayRequests();
        Settings::put('business', ['name' => 'PRIVATE BILLING NAME', 'address' => 'PRIVATE BILLING ADDRESS', 'country' => 'CA']);
        Settings::put('ai', ['enabled' => true, 'api_key' => 'mock-only', 'model' => 'test-model', 'daily_request_limit' => 100, 'daily_token_limit' => 100000]);
        $records = app(RecordRepository::class);
        $logo = $records->create('files', ['visibility' => 'public', 'initial_visibility' => 'public', 'mime' => 'image/png', 'status' => 'ready']);
        $records->create('settings', ['og_image' => '/files/'.$logo['id'], 'og_image_alt' => 'Approved company image', 'google_verification' => 'PUBLIC-VERIFICATION'], 'publishing');
        $this->actingAs(new CmsUser(['id' => 'owner', 'name' => 'Owner', 'email' => 'owner@example.test', 'role' => 'owner']));
        $contact = $this->postJson('/api/v1/pages', ['title' => 'Contact our team', 'slug' => 'contact-team', 'type' => 'contact'])->assertCreated()->json('data');
        $this->postJson('/api/v1/pages/'.$contact['id'].'/publish', ['expected_version' => $contact['version']])->assertOk();
        $website = app(WebsiteSettings::class);
        $record = $website->get();
        $draft = $record['draft'];
        $draft['profile'] = array_replace($draft['profile'], ['name' => 'Approved public company', 'phone' => '+1 416 555 0102', 'email' => 'public@example.test', 'address' => 'PRIVATE OPERATING ADDRESS', 'address_visibility' => 'hidden']);
        $draft['appearance']['colors']['primary'] = '#123456';
        $draft['appearance']['heading_font_id'] = 'lora';
        $draft['appearance']['logo_media_id'] = $logo['id'];
        $draft['appearance']['favicon_media_id'] = $logo['id'];
        $draft['navigation'] = ['primary' => [['label' => 'Talk to our team', 'kind' => 'page', 'page_id' => $contact['id']]], 'footer' => [['label' => 'Public policy', 'kind' => 'url', 'url' => '/policies']], 'header_button' => ['label' => 'Book a visit', 'kind' => 'phone', 'style' => 'secondary'], 'show_client_portal' => false];
        $record = $website->update($draft, $record['version'], 'owner');
        $record = $website->publish($record['version'], 'owner');
        $draft['profile']['name'] = 'UNPUBLISHED COMPANY NAME';
        $draft['appearance']['colors']['primary'] = '#FFFFFF';
        $draft['navigation']['primary'][0]['label'] = 'UNPUBLISHED NAVIGATION';
        $website->update($draft, $record['version'], 'owner');
        Auth::forgetGuards();
        $response = $this->get('/tools/project-scope')->assertOk()->assertHeader('X-Robots-Tag', 'index,follow')
            ->assertSee('Approved public company')->assertSee('Talk to our team')->assertSee('/p/contact-team', false)->assertSee('Public policy')->assertSee('Book a visit')
            ->assertSee('tel:+14165550102', false)->assertSee('public@example.test')->assertSee('--website-primary:#123456', false)->assertSee('Lora')
            ->assertSee('rel="icon" href="/files/'.$logo['id'].'"', false)->assertSee('name="twitter:card" content="summary_large_image"', false)
            ->assertSee('property="og:image" content="https://contractor.example/files/'.$logo['id'].'"', false)->assertSee('name="twitter:image:alt" content="Approved company image"', false)
            ->assertSee('name="google-site-verification" content="PUBLIC-VERIFICATION"', false)->assertSee('property="og:locale" content="en_CA"', false)
            ->assertSee('data-tool-form', false)->assertSee('data-report-form', false)->assertDontSee('Client portal')->assertDontSee('PRIVATE')->assertDontSee('UNPUBLISHED')->assertDontSee('PostalAddress');
        preg_match('~<script[^>]*type="application/ld[+]json"[^>]*>(.*?)</script>~s', $response->getContent(), $matches);
        $graph = collect(json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR)['@graph'])->keyBy('@id');
        $this->assertSame('Approved public company', $graph['https://contractor.example/#business']['name']);
        $this->assertSame('https://contractor.example/files/'.$logo['id'], $graph['https://contractor.example/#business']['logo']);
        $this->assertSame('WebApplication', $graph['https://contractor.example/tools/project-scope#page']['@type']);
        Http::assertNothingSent();
    }

    private function graph(array $page, array $business): array
    {
        return json_decode((new SeoBuilder)->build($page, $business)['schema'], true, 512, JSON_THROW_ON_ERROR)['@graph'];
    }

    private function page(string $type = 'home'): array
    {
        return ['id' => 'page', 'title' => 'Actual page', 'type' => $type, 'slug' => 'actual-page', 'locale' => 'en-CA', 'seo' => []];
    }
}

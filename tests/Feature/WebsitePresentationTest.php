<?php

/** Author: ramanpal singh | URL: https://kwebby.com */

namespace Tests\Feature;

use App\Contracts\RecordRepository;
use App\Identity\CmsUser;
use App\Publishing\FontLibrary;
use App\Publishing\HomepageSections;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class WebsitePresentationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cms.data_provider' => 'postgres', 'cms.mfa_required' => false]);
        $this->staff();
        Settings::put('business', ['name' => 'Original company', 'phone' => '+1 416 555 0100', 'address' => 'PRIVATE BILLING ADDRESS', 'tax_id' => 'PRIVATE TAX ID']);
    }

    public function test_public_appearance_navigation_and_contact_details_are_isolated_from_drafts(): void
    {
        $service = $this->page(['title' => 'Service page', 'slug' => 'service-page', 'type' => 'service']);
        $this->publishPage($service);
        $site = $this->getJson('/api/v1/website')->assertOk()->json('data');
        $site['draft']['profile']['name'] = 'Published contractor';
        $site['draft']['appearance']['heading_font_id'] = 'lora';
        $site['draft']['appearance']['colors']['primary'] = '#123456';
        $site['draft']['appearance']['type_scale'] = 'large';
        $site['draft']['appearance']['button_radius'] = 'rounded';
        $site['draft']['navigation']['primary'] = [['label' => 'Explore our service', 'kind' => 'page', 'page_id' => $service['id']]];
        $site['draft']['navigation']['header_button'] = ['label' => 'Call the team', 'kind' => 'phone', 'style' => 'secondary'];
        $site = $this->saveSite($site);
        $this->get('/')->assertOk()->assertDontSee('Published contractor')->assertDontSee('#123456');
        $this->get('/api/v1/website/preview')->assertOk()->assertSee('Published contractor')->assertSee('#123456')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $site = $this->postJson('/api/v1/website/publish', ['expected_version' => $site['version']])->assertOk()->json('data');
        $response = $this->get('/')->assertOk()->assertSee('Published contractor')->assertSee('Explore our service')->assertSee('href="/p/service-page"', false)->assertSee('href="tel:+14165550100"', false)->assertSee('home-button-secondary')->assertSee('--website-primary:#123456', false)->assertSee('--website-heading-font:"Lora",serif', false)->assertSee('--website-scale:1.12', false)->assertSee('--website-radius:24px', false)->assertDontSee('PRIVATE');
        $this->assertStringNotContainsString('fonts.googleapis.com', $response->getContent());
        $site['draft']['profile']['name'] = 'Unpublished business name';
        $this->saveSite($site);
        $this->get('/')->assertOk()->assertDontSee('Unpublished business name')->assertSee('Published contractor');
        $this->get('/api/v1/website/preview')->assertOk()->assertSee('Unpublished business name');
    }

    public function test_guided_homepage_renders_one_heading_and_form_and_captures_an_actual_lead(): void
    {
        $document = app(HomepageSections::class)->presets()['service']['document'];
        $document['sections'][0]['heading'] = 'Expert help for your next project';
        $document['sections'][0]['body'] = [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Tell our team what you need.', 'styles' => []]]]];
        $home = $this->page(['title' => 'Home SEO title', 'slug' => 'home', 'type' => 'home', 'home_sections' => $document, 'seo' => ['description' => 'Helpful contractor services.']]);
        $this->get('/')->assertDontSee('Expert help for your next project');
        $this->get('/api/v1/pages/'.$home['id'].'/preview')->assertOk()->assertSee('Expert help for your next project')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $home = $this->publishPage($home);
        Auth::forgetGuards();
        $response = $this->get('/')->assertOk()->assertSee('Expert help for your next project')->assertSee('Tell our team what you need.')->assertSee('property="og:title"', false)->assertSee('name="twitter:card"', false);
        $this->assertSame(1, substr_count($response->getContent(), '<h1'));
        $this->assertSame(1, substr_count($response->getContent(), 'data-lead-form'));
        $this->postJson('/api/v1/public/leads', ['name' => 'Website visitor', 'email' => 'visitor@example.test', 'service' => 'Project consultation', 'notes' => 'Please arrange an assessment.', 'marketing_consent' => false])->assertSuccessful();
        $lead = app(RecordRepository::class)->all('leads')[0];
        $this->assertSame('website', $lead['source']);
        $this->assertFalse($lead['marketing_consent']);
        $this->staff();
        $document['sections'][0]['heading'] = 'Unpublished home revision';
        $this->putJson('/api/v1/pages/'.$home['id'], ['expected_version' => $home['version'], 'home_sections' => $document])->assertOk();
        $this->get('/')->assertDontSee('Unpublished home revision')->assertSee('Expert help for your next project');
    }

    public function test_managed_navigation_follows_published_slug_changes_only(): void
    {
        $service = $this->publishPage($this->page(['title' => 'Repair', 'slug' => 'repair', 'type' => 'service']));
        $site = $this->getJson('/api/v1/website')->json('data');
        $site['draft']['navigation']['primary'] = [['label' => 'Book a repair', 'kind' => 'page', 'page_id' => $service['id']]];
        $site = $this->saveSite($site);
        $this->postJson('/api/v1/website/publish', ['expected_version' => $site['version']])->assertOk();
        $service = $this->putJson('/api/v1/pages/'.$service['id'], ['expected_version' => $service['version'], 'slug' => 'expert-repair'])->assertOk()->json('data');
        $this->get('/')->assertSee('href="/p/repair"', false)->assertDontSee('href="/p/expert-repair"', false);
        $this->publishPage($service);
        $this->get('/')->assertSee('href="/p/expert-repair"', false);
        $this->get('/p/repair')->assertRedirect(config('app.url').'/p/expert-repair');
    }

    public function test_header_section_links_resolve_to_actual_homepage_ids(): void
    {
        $document = app(HomepageSections::class)->presets()['service']['document'];
        $this->publishPage($this->page(['title' => 'Home', 'slug' => 'home', 'type' => 'home', 'home_sections' => $document]));
        $site = $this->getJson('/api/v1/website')->json('data');
        foreach (['hero' => 'home-hero', 'contact' => 'request-service', 'request-service' => 'request-service'] as $anchor => $target) {
            $site['draft']['navigation']['header_button'] = ['label' => 'Go to section', 'kind' => 'anchor', 'anchor' => $anchor, 'style' => 'primary'];
            $site = $this->saveSite($site);
            $site = $this->postJson('/api/v1/website/publish', ['expected_version' => $site['version']])->assertOk()->json('data');
            $this->get('/')->assertOk()->assertSee('href="/#'.$target.'"', false)->assertSee('id="'.$target.'"', false);
        }
    }

    public function test_dedicated_settings_pages_and_previews_require_publishing_access(): void
    {
        $paths = ['/app/website/homepage', '/app/website/appearance', '/app/website/navigation', '/app/website/local-seo', '/app/website/local-seo/business-profile', '/app/website/local-seo/locations', '/app/website/local-seo/locations/north', '/app/website/local-seo/page-plan', '/app/website/local-seo/citations'];
        foreach ($paths as $path) {
            $this->get($path)->assertOk();
        }
        $this->staff('client');
        foreach ([...$paths, '/api/v1/website/preview'] as $path) {
            $this->get($path)->assertForbidden();
        }
        Auth::forgetGuards();
        $this->getJson('/api/v1/website/preview')->assertUnauthorized();
    }

    public function test_font_assets_are_local_complete_and_retain_their_licenses(): void
    {
        $catalog = app(FontLibrary::class)->catalog();
        $this->assertCount(8, $catalog);
        foreach ($catalog as $font) {
            $this->assertStringContainsString('SIL OPEN FONT LICENSE', file_get_contents(public_path($font['license_url'])));
            foreach ($font['files'] as $file) {
                $this->assertSame($file['sha256'], hash_file('sha256', public_path($file['path'])));
            }
        }
        $css = file_get_contents(public_path('assets/website-fonts.css'));
        $this->assertStringNotContainsString('https://', str_replace('https://kwebby.com', '', $css));
        $this->get('/assets/home-sections.css')->assertOk()->assertHeader('Content-Type', 'text/css; charset=UTF-8')->assertSee('.home-sections')->assertSee('--website-heading-font');
    }

    public function test_workspace_shell_never_serializes_private_billing_settings_to_a_client(): void
    {
        $this->staff('client');
        $this->get('/app')->assertOk()->assertDontSee('PRIVATE BILLING ADDRESS')->assertDontSee('PRIVATE TAX ID');
        $this->get('/app')->assertInertia(fn (AssertableInertia $page) => $page->component('Workspace')->where('business.name', 'Original company')->missing('business.address')->missing('business.tax_id'));
    }

    private function page(array $data): array
    {
        return $this->postJson('/api/v1/pages', $data)->assertCreated()->json('data');
    }

    private function publishPage(array $page): array
    {
        return $this->postJson('/api/v1/pages/'.$page['id'].'/publish', ['expected_version' => $page['version']])->assertOk()->json('data');
    }

    private function saveSite(array $site): array
    {
        return $this->putJson('/api/v1/website', ['expected_version' => $site['version'], 'draft' => $site['draft']])->assertOk()->json('data');
    }

    private function staff(string $role = 'owner'): void
    {
        $this->actingAs(new CmsUser(['id' => $role, 'name' => 'Test person', 'email' => $role.'@example.test', 'role' => $role, 'active' => true, 'email_verified_at' => now()->toIso8601String()]));
    }
}

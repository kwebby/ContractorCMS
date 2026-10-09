<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace Tests\Feature;

use App\Contracts\RecordRepository;
use App\Identity\CmsUser;
use App\Publishing\HomepageSections;
use App\Publishing\PublishingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class HomepageSectionsTest extends TestCase
{
    use RefreshDatabase;

    private RecordRepository $records;

    private HomepageSections $sections;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cms.data_provider' => 'postgres', 'cms.mfa_required' => false]);
        $this->records = app(RecordRepository::class);
        $this->sections = app(HomepageSections::class);
        $this->actingAs(new CmsUser(['id' => 'owner', 'name' => 'Owner', 'email' => 'owner@example.test', 'role' => 'owner']));
    }

    public function test_presets_are_valid_and_do_not_publish_placeholder_proof(): void
    {
        $presets = $this->sections->presets();
        $this->assertCount(4, $presets);
        foreach ($presets as $preset) {
            $document = $this->sections->validate($preset['document']);
            $enabled = array_filter($document['sections'], fn (array $section): bool => $section['enabled']);
            $this->assertSame(['hero', 'contact'], array_values(array_column($enabled, 'type')));
            $this->sections->validateForPublication($document);
        }
    }

    public function test_unknown_fields_duplicate_ids_h1_and_unbounded_sections_are_rejected(): void
    {
        $invalid = [];
        $document = $this->document();
        $document['sections'][0]['html'] = '<script>alert(1)</script>';
        $invalid[] = $document;
        $document = $this->document();
        $document['sections'][] = $document['sections'][0];
        $invalid[] = $document;
        $document = $this->document();
        $document['sections'][0]['body'] = [['type' => 'heading', 'props' => ['level' => 1], 'content' => 'Another H1']];
        $invalid[] = $document;
        $document = $this->document();
        $document['sections'] = array_fill(0, 25, $document['sections'][0]);
        $invalid[] = $document;
        $document = $this->document();
        $document['sections'][0]['enabled'] = false;
        $invalid[] = $document;
        foreach ($invalid as $candidate) {
            $this->assertInvalid(fn () => $this->sections->validate($candidate));
        }
    }

    public function test_typed_items_and_button_destinations_reject_executable_urls_and_wrong_fields(): void
    {
        foreach (['javascript:alert(1)', '//evil.test', 'https://user:password@example.test', '/api/v1/pages/private/preview'] as $url) {
            $document = $this->document();
            $document['sections'][0]['buttons'] = [['label' => 'Open', 'kind' => 'url', 'url' => $url, 'style' => 'primary']];
            $this->assertInvalid(fn () => $this->sections->validate($document));
        }
        $document = $this->document();
        $document['sections'][] = $this->section('faq', [['question' => 'When?', 'answer' => 'After review.', 'rating' => 5]]);
        $this->assertInvalid(fn () => $this->sections->validate($document));
        $document = $this->document();
        $document['sections'][0]['buttons'] = [['label' => 'Call', 'kind' => 'phone', 'url' => 'https://example.test', 'style' => 'primary']];
        $this->assertInvalid(fn () => $this->sections->validate($document));
    }

    public function test_draft_media_requires_publishing_permission_and_private_media_is_never_accepted(): void
    {
        $this->records->create('files', ['visibility' => 'draft', 'status' => 'ready', 'mime' => 'image/png'], 'draft-image');
        $this->records->create('files', ['visibility' => 'private', 'status' => 'ready', 'mime' => 'image/png'], 'private-image');
        $document = $this->document();
        $document['sections'][0]['image'] = $this->image('draft-image');
        $this->sections->validate($document);
        $this->assertInvalid(fn () => $this->sections->validate($document, false));
        $this->actingAs(new CmsUser(['id' => 'client', 'name' => 'Client', 'email' => 'client@example.test', 'role' => 'client']));
        $this->assertInvalid(fn () => $this->sections->validate($document));
        $document['sections'][0]['image'] = $this->image('private-image');
        $this->assertInvalid(fn () => $this->sections->validate($document));
    }

    public function test_publication_promotes_only_enabled_media_and_preserves_previous_homepage_snapshot(): void
    {
        foreach (['hero-image', 'draft-only-image', 'body-image'] as $id) {
            $this->records->create('files', ['visibility' => 'draft', 'status' => 'ready', 'mime' => 'image/png'], $id);
        }
        $document = $this->document();
        $document['sections'][0]['heading'] = 'Original published heading';
        $document['sections'][0]['image'] = $this->image('hero-image');
        $document['sections'][0]['body'] = [['type' => 'image', 'props' => ['mediaId' => 'body-image', 'alt' => 'Project overview']]];
        $document['sections'][] = $this->section('about') + ['image' => $this->image('draft-only-image')];
        $document['sections'][2]['enabled'] = false;
        $publishing = app(PublishingService::class);
        $page = $publishing->create(['title' => 'Home', 'slug' => 'home', 'type' => 'home', 'home_sections' => $document], 'owner');
        $page = $publishing->publish($page['id'], $page['version'], 'owner');
        $this->assertSame('public', $this->records->get('files', 'hero-image')['visibility']);
        $this->assertSame('public', $this->records->get('files', 'body-image')['visibility']);
        $this->assertSame('draft', $this->records->get('files', 'draft-only-image')['visibility']);
        $this->assertEqualsCanonicalizing(['hero-image', 'body-image'], $page['published']['media_ids']);
        $document['sections'][0]['heading'] = 'A private draft heading';
        $updated = $publishing->update($page['id'], ['expected_version' => $page['version'], 'home_sections' => $document], 'owner');
        $this->assertSame('Original published heading', $updated['published']['home_sections']['sections'][0]['heading']);
        $this->assertSame('A private draft heading', $updated['home_sections']['sections'][0]['heading']);
        $this->assertNotEmpty($this->records->all('page_revisions', ['page_id' => $page['id']]));
    }

    public function test_draft_references_are_authorable_but_cannot_be_published_until_the_target_is_published(): void
    {
        $publishing = app(PublishingService::class);
        $service = $publishing->create(['title' => 'Drain repair', 'slug' => 'drains', 'type' => 'service'], 'owner');
        $document = $this->document();
        $document['sections'][] = $this->section('services', [['page_id' => $service['id']]]);
        $this->sections->validate($document);
        $this->assertInvalid(fn () => $this->sections->validateForPublication($document));
        $service = $publishing->publish($service['id'], $service['version'], 'owner');
        $this->sections->validateForPublication($document);
        $html = $this->sections->render($document);
        $this->assertStringContainsString('href="/p/drains"', $html);
        $this->assertStringContainsString('Drain repair', $html);
        $publishing->update($service['id'], ['expected_version' => $service['version'], 'title' => 'Private draft title', 'slug' => 'private-draft-slug'], 'owner');
        $html = $this->sections->render($document);
        $this->assertStringNotContainsString('Private draft title', $html);
        $this->assertStringNotContainsString('private-draft-slug', $html);
        $service = $this->records->get('pages', $service['id']);
        $publishing->unpublish($service['id'], $service['version'], 'owner');
        $this->assertStringNotContainsString('/p/drains', $this->sections->render($document));
    }

    public function test_testimonials_require_confirmation_offers_expire_and_text_is_escaped(): void
    {
        $document = $this->document();
        $document['sections'][0]['heading'] = '<img src=x onerror=alert(1)>';
        $document['sections'][] = $this->section('reviews', [['text' => 'Actual feedback', 'author' => 'A customer', 'permission_confirmed' => false]]);
        $this->sections->validate($document);
        $this->assertInvalid(fn () => $this->sections->validateForPublication($document));
        $document['sections'][2]['items'][0]['permission_confirmed'] = true;
        $document['sections'][] = $this->section('offers', [
            ['title' => 'Expired offer', 'ends_at' => now()->subDay()->toDateString()],
            ['title' => 'Future offer', 'starts_at' => now()->addDay()->toDateString()],
            ['title' => 'Current offer', 'terms' => 'Call for eligibility'],
        ]);
        $document = $this->sections->validate($document);
        $html = $this->sections->render($document);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
        $this->assertStringNotContainsString('<img src=x onerror', $html);
        $this->assertStringNotContainsString('Expired offer', $html);
        $this->assertStringNotContainsString('Future offer', $html);
        $this->assertStringContainsString('Current offer', $html);
        $this->assertStringContainsString('Actual feedback', $html);
        $this->assertSame(1, substr_count($html, 'id="request-service"'));
    }

    public function test_sections_are_rejected_for_other_pages_and_expert_mode_retains_existing_content(): void
    {
        $publishing = app(PublishingService::class);
        $this->assertInvalid(fn () => $publishing->create(['title' => 'Other', 'slug' => 'other', 'type' => 'service', 'home_sections' => $this->document()], 'owner'));
        $document = $this->document();
        $document['mode'] = 'expert';
        $this->assertSame('', $this->sections->render($document));
        $page = $publishing->create(['title' => 'Home', 'slug' => 'home', 'type' => 'home', 'content' => [['type' => 'paragraph', 'content' => 'Expert writing']], 'home_sections' => $document], 'owner');
        $this->assertSame('Expert writing', $page['content'][0]['content']);
    }

    public function test_missing_body_images_and_disabled_tools_do_not_expose_private_content(): void
    {
        $document = $this->document();
        $document['sections'][0]['body'] = [['type' => 'image', 'props' => ['mediaId' => 'deleted-image']], ['type' => 'paragraph', 'content' => 'Visible copy']];
        $document['sections'][] = $this->section('lead_tool') + ['tool_key' => 'service-visit-preparation'];
        $html = $this->sections->render($document);
        $this->assertStringContainsString('Visible copy', $html);
        $this->assertStringNotContainsString('deleted-image', $html);
        $this->assertStringNotContainsString('home-section-lead_tool', $html);
    }

    public function test_service_cards_cannot_reference_other_page_types_and_anchor_targets_must_be_enabled(): void
    {
        $article = app(PublishingService::class)->create(['title' => 'Article', 'slug' => 'article', 'type' => 'article'], 'owner');
        $document = $this->document();
        $document['sections'][] = $this->section('services', [['page_id' => $article['id']]]);
        $this->assertInvalid(fn () => $this->sections->validate($document));
        $document = $this->document();
        $document['sections'][1]['enabled'] = false;
        $document['sections'][0]['buttons'] = [['label' => 'Contact', 'kind' => 'anchor', 'anchor' => 'contact', 'style' => 'primary']];
        $this->assertInvalid(fn () => $this->sections->validateForPublication($document));
    }

    private function document(): array
    {
        return ['schema_version' => 1, 'mode' => 'guided', 'sections' => [$this->section('hero'), $this->section('contact')]];
    }

    private function section(string $type, array $items = []): array
    {
        return ['id' => str_replace('_', '-', $type), 'type' => $type, 'enabled' => true, 'variant' => 'standard', 'heading' => ucfirst($type), 'body' => [], 'buttons' => [], 'items' => $items];
    }

    private function image(string $id): array
    {
        return ['media_id' => $id, 'alt' => 'An original project', 'decorative' => false, 'focal_x' => 50, 'focal_y' => 50];
    }

    private function assertInvalid(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected a validation error.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }
    }
}

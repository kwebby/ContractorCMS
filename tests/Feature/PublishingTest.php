<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace Tests\Feature;

use App\Contracts\RecordRepository;
use App\Http\Controllers\PublishingController;
use App\Identity\CmsUser;
use App\Publishing\PublishingService;
use App\Publishing\SeoBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PublishingTest extends TestCase
{
    use RefreshDatabase;

    private RecordRepository $records;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cms.data_provider' => 'postgres', 'cms.mfa_required' => false, 'app.url' => 'http://localhost']);
        $this->records = app(RecordRepository::class);
        if (! Route::has('public.page')) {
            Route::middleware('web')->group(base_path('routes/publishing.php'));
        }
        Route::get('/', [PublishingController::class, 'home']);
    }

    public function test_client_cannot_write_or_preview_pages(): void
    {
        $this->actingAs(new CmsUser(['id' => 'client-1', 'name' => 'Client', 'email' => 'client@example.test', 'role' => 'client']));
        $this->postJson('/api/v1/pages', $this->pageData())->assertForbidden();
        $this->getJson('/api/v1/pages')->assertForbidden();
        $this->get('/api/v1/pages/any/preview')->assertForbidden();
    }

    public function test_publishing_snapshot_draft_isolation_slug_redirects_and_conflict(): void
    {
        $this->owner();
        $page = $this->postJson('/api/v1/pages', $this->pageData())->assertCreated()->json('data');
        $this->get('/p/drain-repair')->assertNotFound();
        $page = $this->postJson('/api/v1/pages/'.$page['id'].'/publish', ['expected_version' => $page['version']])->assertOk()->json('data');
        $this->get('/p/drain-repair')->assertOk()->assertSee('Original public copy');
        $edited = $this->putJson('/api/v1/pages/'.$page['id'], ['expected_version' => $page['version'], 'title' => 'New title', 'slug' => 'drain-services', 'content' => [['type' => 'paragraph', 'content' => 'Unpublished changes']]])->assertOk()->json('data');
        $this->get('/p/drain-repair')->assertOk()->assertDontSee('Unpublished changes');
        $this->get('/p/drain-services')->assertNotFound();
        $this->postJson('/api/v1/pages/'.$page['id'].'/publish', ['expected_version' => $page['version']])->assertConflict();
        $published = $this->postJson('/api/v1/pages/'.$page['id'].'/publish', ['expected_version' => $edited['version']])->assertOk()->json('data');
        $this->get('/p/drain-repair')->assertStatus(301)->assertRedirect('http://localhost/p/drain-services');
        $this->get('/p/drain-services')->assertOk()->assertSee('Unpublished changes');
        $this->getJson('/api/v1/pages/'.$page['id'].'/revisions')->assertOk()->assertJsonCount(3, 'data');
        $this->postJson('/api/v1/pages/'.$page['id'].'/unpublish', ['expected_version' => $published['version']])->assertOk();
        $this->get('/p/drain-services')->assertNotFound();
        $this->get('/p/drain-repair')->assertNotFound();
    }

    public function test_metadata_schema_sitemap_and_private_preview_are_server_rendered(): void
    {
        $this->owner();
        $this->records->create('settings', ['name' => 'River Plumbing', 'trade' => 'plumbing', 'phone' => '+1 555 0100'], 'business');
        $page = app(PublishingService::class)->create($this->pageData() + ['seo' => ['title' => 'Drain repair | River', 'description' => 'Repair questions answered.', 'og_title' => 'Drain repairs', 'schema_mode' => 'auto']], 'owner');
        app(PublishingService::class)->publish($page['id'], $page['version'], 'owner');
        $html = $this->get('/p/drain-repair')->assertOk()->getContent();
        $this->assertStringContainsString('<title>Drain repair | River</title>', $html);
        $this->assertStringContainsString('property="og:title" content="Drain repairs"', $html);
        $this->assertStringContainsString('name="twitter:card"', $html);
        $this->assertStringContainsString('"@type":"Plumber"', $html);
        $this->assertStringContainsString('"@type":"Service"', $html);
        $this->assertStringNotContainsString('aggregateRating', $html);
        $this->get('/sitemap.xml')->assertOk()->assertSee('http://localhost/p/drain-repair', false)->assertDontSee('/api/');
        $this->get('/api/v1/pages/'.$page['id'].'/preview')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->get('/robots.txt')->assertOk()->assertSee('Disallow: /portal/', false);
    }

    public function test_schema_rejects_external_context_and_protected_entity_overrides(): void
    {
        $this->owner();
        $this->postJson('/api/v1/pages', $this->pageData() + ['seo' => ['schema_mode' => 'custom', 'schema_custom' => ['@type' => 'WebPage', '@context' => 'https://evil.test/context']]])->assertUnprocessable();
        $this->postJson('/api/v1/pages', $this->pageData() + ['seo' => ['schema_mode' => 'custom', 'schema_custom' => ['@type' => 'WebPage', 'aggregateRating' => ['ratingValue' => 5]]]])->assertUnprocessable();
    }

    public function test_private_media_cannot_be_published(): void
    {
        $this->owner();
        $this->records->create('files', ['visibility' => 'private', 'status' => 'ready', 'mime' => 'image/png'], 'private-photo');
        $this->postJson('/api/v1/pages', array_replace($this->pageData(), ['content' => [['type' => 'image', 'props' => ['url' => '/files/private-photo']]]]))->assertUnprocessable();
    }

    public function test_canonical_override_and_noindex_pages_are_excluded_from_sitemap(): void
    {
        $seo = new SeoBuilder;
        $page = ['slug' => 'help', 'type' => 'page', 'seo' => ['canonical' => 'https://different.example/help']];
        $this->assertFalse($seo->isIndexable($page));
        $page['seo'] = ['robots' => 'noindex,follow'];
        $this->assertFalse($seo->isIndexable($page));
        $page['seo'] = [];
        $this->assertTrue($seo->isIndexable($page));
    }

    public function test_hreflang_only_links_published_translations_with_matching_group(): void
    {
        $base = ['id' => 'en', 'slug' => 'help', 'type' => 'page', 'title' => 'Help', 'locale' => 'en-US', 'translation_group' => 'help', 'seo' => []];
        $fr = array_replace($base, ['id' => 'fr', 'slug' => 'aide', 'locale' => 'fr-CA']);
        $hidden = array_replace($base, ['id' => 'hidden', 'locale' => 'en-CA', 'seo' => ['robots' => 'noindex,follow']]);
        $meta = (new SeoBuilder)->build($base, [], [$base, $fr, $hidden]);
        $this->assertSame(['en-US' => 'http://localhost/p/help', 'fr-CA' => 'http://localhost/p/aide'], $meta['alternates']);
    }

    private function owner(): void
    {
        $this->actingAs(new CmsUser(['id' => 'owner', 'name' => 'Owner', 'email' => 'owner@example.test', 'role' => 'owner']));
    }

    private function pageData(): array
    {
        return ['title' => 'Drain repair', 'slug' => 'drain-repair', 'type' => 'service', 'locale' => 'en-US', 'excerpt' => 'Local help with your drains.', 'content' => [['type' => 'paragraph', 'content' => 'Original public copy']]];
    }
}

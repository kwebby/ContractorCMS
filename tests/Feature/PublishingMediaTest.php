<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace Tests\Feature;

use App\Contracts\RecordRepository;
use App\Identity\CmsUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublishingMediaTest extends TestCase
{
    use RefreshDatabase;

    private RecordRepository $records;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cms.data_provider' => 'postgres', 'cms.mfa_required' => false, 'app.url' => 'http://localhost']);
        Storage::fake('local');
        $this->records = app(RecordRepository::class);
        $this->staff();
    }

    public function test_draft_uploads_require_publishing_permission_and_are_never_anonymous_downloads(): void
    {
        $file = $this->upload();
        $this->assertSame('draft', $file['visibility']);
        $this->assertSame('private-until-first-publication', $file['publication_policy']);
        Auth::forgetGuards();
        $this->get('/files/'.$file['id'])->assertForbidden();
        $this->staff('office');
        $this->get('/files/'.$file['id'])->assertForbidden();
        $this->postJson('/api/v1/files', ['visibility' => 'draft', 'file' => UploadedFile::fake()->image('draft.png')])->assertForbidden();
        $this->staff('marketing');
        $this->get('/files/'.$file['id'])->assertOk()->assertHeader('X-Robots-Tag', 'noindex');
        $this->postJson('/api/v1/files', ['visibility' => 'draft', 'file' => UploadedFile::fake()->createWithContent('notes.txt', 'Private notes')])->assertUnprocessable();
    }

    public function test_publishing_atomically_promotes_content_layout_and_social_images(): void
    {
        $content = $this->upload();
        $layout = $this->upload();
        $social = $this->upload();
        $page = $this->postJson('/api/v1/pages', [
            'title' => 'Project story', 'slug' => 'project-story',
            'content' => [['type' => 'image', 'props' => ['url' => $content['url']]]],
            'layout' => ['components' => [['type' => 'image', 'tagName' => 'img', 'attributes' => ['src' => $layout['url'], 'alt' => 'Project']]]],
            'seo' => ['og_image' => $social['url']],
        ])->assertCreated()->json('data');
        $this->get('/api/v1/pages/'.$page['id'].'/preview')->assertOk()->assertSee($content['url'], false)->assertSee($layout['url'], false)->assertSee($social['url'], false);
        Auth::forgetGuards();
        foreach ([$content, $layout, $social] as $file) {
            $this->get($file['url'])->assertForbidden();
        }
        $this->staff();
        $published = $this->postJson('/api/v1/pages/'.$page['id'].'/publish', ['expected_version' => $page['version']])->assertOk()->json('data');
        $this->assertEqualsCanonicalizing([$content['id'], $layout['id'], $social['id']], $published['published']['media_ids']);
        Auth::forgetGuards();
        $this->get('/p/project-story')->assertOk()->assertSee($content['url'], false)->assertSee($layout['url'], false)->assertSee($social['url'], false);
        foreach ([$content, $layout, $social] as $file) {
            $this->get($file['url'])->assertOk();
            $record = $this->records->get('files', $file['id']);
            $this->assertSame('public', $record['visibility']);
            $this->assertSame('permanent-after-publication', $record['publication_policy']);
            $this->assertSame($page['id'], $record['first_published_page_id']);
        }
    }

    public function test_stale_publish_rolls_back_all_draft_media_promotions(): void
    {
        $file = $this->upload();
        $page = $this->page($file);
        $this->putJson('/api/v1/pages/'.$page['id'], ['expected_version' => $page['version'], 'title' => 'Updated title'])->assertOk();
        $this->postJson('/api/v1/pages/'.$page['id'].'/publish', ['expected_version' => $page['version']])->assertConflict();
        $this->assertSame('draft', $this->records->get('files', $file['id'])['visibility']);
        $this->assertArrayNotHasKey('first_published_at', $this->records->get('files', $file['id']));
        Auth::forgetGuards();
        $this->get($file['url'])->assertForbidden();
        $this->get('/p/project-story')->assertNotFound();
    }

    public function test_new_image_in_draft_revision_stays_private_until_that_revision_is_published(): void
    {
        $original = $this->upload();
        $page = $this->page($original);
        $page = $this->postJson('/api/v1/pages/'.$page['id'].'/publish', ['expected_version' => $page['version']])->assertOk()->json('data');
        $draft = $this->upload();
        $page = $this->putJson('/api/v1/pages/'.$page['id'], ['expected_version' => $page['version'], 'content' => [['type' => 'image', 'props' => ['mediaId' => $draft['id']]]]])->assertOk()->json('data');
        $this->get('/api/v1/pages/'.$page['id'].'/preview')->assertOk()->assertSee($draft['url'], false);
        Auth::forgetGuards();
        $this->get('/p/project-story')->assertOk()->assertSee($original['url'], false)->assertDontSee($draft['url'], false);
        $this->get($draft['url'])->assertForbidden();
        $this->get($original['url'])->assertOk();
        $this->staff();
        $page = $this->postJson('/api/v1/pages/'.$page['id'].'/publish', ['expected_version' => $page['version']])->assertOk()->json('data');
        $this->postJson('/api/v1/pages/'.$page['id'].'/unpublish', ['expected_version' => $page['version']])->assertOk();
        Auth::forgetGuards();
        $this->get('/p/project-story')->assertNotFound();
        $this->get($original['url'])->assertOk();
        $this->get($draft['url'])->assertOk();
    }

    public function test_global_social_settings_only_accept_explicitly_public_or_previously_published_images(): void
    {
        $draft = $this->upload();
        $this->putJson('/api/v1/seo/settings', ['og_image' => $draft['url']])->assertUnprocessable();
        $public = $this->upload('public');
        $this->putJson('/api/v1/seo/settings', ['og_image' => $public['url']])->assertOk();
        Auth::forgetGuards();
        $this->get($draft['url'])->assertForbidden();
        $this->get($public['url'])->assertOk();
    }

    private function staff(string $role = 'owner'): void
    {
        $this->actingAs(new CmsUser(['id' => $role, 'name' => ucfirst($role), 'email' => $role.'@example.test', 'role' => $role]));
    }

    private function upload(string $visibility = 'draft'): array
    {
        return $this->postJson('/api/v1/files', ['visibility' => $visibility, 'file' => UploadedFile::fake()->image('project.png')])->assertCreated()->json('data');
    }

    private function page(array $file): array
    {
        return $this->postJson('/api/v1/pages', ['title' => 'Project story', 'slug' => 'project-story', 'content' => [['type' => 'image', 'props' => ['mediaId' => $file['id']]]]])->assertCreated()->json('data');
    }
}

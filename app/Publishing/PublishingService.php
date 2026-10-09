<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace App\Publishing;

use App\Contracts\RecordRepository;
use App\Support\Access;
use App\Support\Outbox;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class PublishingService
{
    public function __construct(private readonly RecordRepository $records, private readonly LayoutRenderer $layouts, private readonly HomepageSections $homepage) {}

    public function create(array $input, string $author): array
    {
        $data = $this->validate($input);

        return $this->records->transaction(function () use ($data, $author): array {
            $this->records->lock('publishing-slugs');
            $this->validateLocation($data, null, false);
            $this->uniqueSlug($data['slug']);

            $page = $this->records->create('pages', $data + ['status' => 'draft', 'published' => null, 'has_unpublished_changes' => true, 'created_by' => $author]);
            Outbox::audit('page.created', ['page_id' => $page['id']]);

            return $page;
        });
    }

    public function update(string $id, array $input, string $author): array
    {
        $expected = Validator::make($input, ['expected_version' => ['required', 'integer', 'min:1']])->validate()['expected_version'];

        return $this->records->transaction(function () use ($id, $input, $author, $expected): array {
            $this->records->lock('publishing-slugs');
            $page = $this->records->get('pages', $id);
            $data = $this->validate(array_replace($page, $input));
            $this->validateLocation($data, $id, false);
            $this->uniqueSlug($data['slug'], $id);
            $this->revision($page, $author, 'edit');
            Outbox::audit('page.updated', ['page_id' => $id]);

            return $this->records->update('pages', $id, $data + ['has_unpublished_changes' => true], (int) $expected);
        });
    }

    public function publish(string $id, int $expected, string $author): array
    {
        return $this->records->transaction(function () use ($id, $expected, $author): array {
            $this->records->lock('publishing-slugs');
            $page = $this->records->get('pages', $id);
            app(ThemeManager::class)->assertHomepageSupport($page);
            $this->validate($page);
            $this->validateLocation($page, $id, true);
            if (isset($page['home_sections'])) {
                $this->homepage->validateForPublication($page['home_sections']);
            }
            $this->uniqueSlug($page['slug'], $id);
            if ($page['type'] === 'home') {
                foreach ($this->published() as $published) {
                    if ($published['type'] === 'home' && $published['id'] !== $id) {
                        throw ValidationException::withMessages(['type' => 'Unpublish the current home page before publishing a new one.']);
                    }
                }
            }
            $this->revision($page, $author, 'publish');
            Outbox::audit('page.published', ['page_id' => $id]);
            $old = $page['published'] ?? null;
            if ($old && $old['slug'] !== $page['slug']) {
                $redirectId = hash('sha256', $old['slug']);
                $redirect = ['from_slug' => $old['slug'], 'page_id' => $id];
                if ($this->records->find('redirects', $redirectId)) {
                    $this->records->update('redirects', $redirectId, $redirect);
                } else {
                    $this->records->create('redirects', $redirect, $redirectId);
                }
            }
            $snapshot = array_diff_key($page, array_flip(['published', 'version', 'has_unpublished_changes']));
            $snapshot['published_at'] = $old['published_at'] ?? now()->toIso8601String();
            $snapshot['updated_at'] = now()->toIso8601String();
            $snapshot['status'] = 'published';
            $snapshot['media_ids'] = $this->promoteDraftMedia($page, $id);

            return $this->records->update('pages', $id, ['status' => 'published', 'published' => $snapshot, 'has_unpublished_changes' => false, 'published_at' => $snapshot['published_at']], $expected);
        });
    }

    public function unpublish(string $id, int $expected, string $author): array
    {
        return $this->records->transaction(function () use ($id, $expected, $author): array {
            $this->records->lock('publishing-slugs');
            $page = $this->records->get('pages', $id);
            $this->revision($page, $author, 'unpublish');
            Outbox::audit('page.unpublished', ['page_id' => $id]);

            return $this->records->update('pages', $id, ['status' => 'draft', 'published' => null, 'has_unpublished_changes' => true], $expected);
        });
    }

    public function archive(string $id, int $expected, string $author): array
    {
        return $this->records->transaction(function () use ($id, $expected, $author): array {
            $this->records->lock('publishing-slugs');
            $page = $this->records->get('pages', $id);
            $this->revision($page, $author, 'archive');
            Outbox::audit('page.archived', ['page_id' => $id]);

            return $this->records->update('pages', $id, ['status' => 'archived', 'published' => null, 'has_unpublished_changes' => true], $expected);
        });
    }

    public function published(): array
    {
        return array_values(array_map(fn (array $page): array => $page['published'], array_filter($this->records->all('pages', ['status' => 'published']), fn (array $page): bool => is_array($page['published'] ?? null))));
    }

    public function renderer(bool $includeDraft = false): BlockNoteRenderer
    {
        return new BlockNoteRenderer(function (string $id) use ($includeDraft): ?string {
            $file = $this->records->find('files', $id);
            $visible = ($file['visibility'] ?? '') === 'public' || ($includeDraft && ($file['visibility'] ?? '') === 'draft' && Access::can(auth()->user(), 'publishing.manage'));

            return $file && $visible && ($file['status'] ?? '') === 'ready' && in_array($file['mime'] ?? '', ['image/png', 'image/jpeg', 'image/webp', 'image/gif'], true) ? '/files/'.$id : null;
        });
    }

    public function validate(array $input, bool $includeDraft = true): array
    {
        $data = Validator::make($input, [
            'title' => ['required', 'string', 'max:180'],
            'slug' => ['required', 'string', 'max:160', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D'],
            'type' => ['sometimes', Rule::in(['home', 'service', 'service-area', 'branch', 'about', 'contact', 'article', 'case-study', 'collection', 'faq', 'careers', 'job', 'tool', 'page'])],
            'locale' => ['sometimes', 'string', 'regex:/^[a-z]{2}(?:-[A-Z]{2})?$/D'],
            'excerpt' => ['nullable', 'string', 'max:500'],
            'author_name' => ['nullable', 'string', 'max:120'],
            'reviewed_at' => ['nullable', 'date'],
            'sources' => ['sometimes', 'array', 'max:20'],
            'sources.*' => ['url:http,https', 'max:2000'],
            'service_area' => ['nullable', 'string', 'max:150'],
            'location_id' => ['sometimes', 'nullable', 'string', 'max:100'],
            'translation_group' => ['nullable', 'string', 'max:100', 'regex:/^[a-zA-Z0-9_-]+$/D'],
            'content' => ['sometimes', 'array'],
            'layout' => ['sometimes', 'array'],
            'home_sections' => ['sometimes', 'nullable', 'array'],
            'faq' => ['sometimes', 'array', 'max:30'],
            'faq.*.question' => ['required', 'string', 'max:300'],
            'faq.*.answer' => ['required', 'string', 'max:3000'],
            'seo' => ['sometimes', 'array'],
            'seo.title' => ['nullable', 'string', 'max:180'],
            'seo.description' => ['nullable', 'string', 'max:500'],
            'seo.canonical' => ['nullable', 'url:http,https', 'max:2000'],
            'seo.robots' => ['sometimes', Rule::in(['index,follow', 'noindex,follow', 'noindex,nofollow'])],
            'seo.og_title' => ['nullable', 'string', 'max:180'],
            'seo.og_description' => ['nullable', 'string', 'max:500'],
            'seo.og_image' => ['nullable', 'string', 'max:2000'],
            'seo.og_image_alt' => ['nullable', 'string', 'max:300'],
            'seo.twitter_card' => ['sometimes', Rule::in(['summary', 'summary_large_image'])],
            'seo.schema_mode' => ['sometimes', Rule::in(['auto', 'preset', 'off', 'custom'])],
            'seo.schema_type' => ['nullable', Rule::in(['WebPage', 'AboutPage', 'ContactPage', 'CollectionPage', 'FAQPage', 'Article', 'BlogPosting', 'WebApplication'])],
            'seo.schema_custom' => ['nullable', 'array'],
        ])->validate();
        $data += ['type' => 'page', 'locale' => 'en-US', 'content' => [], 'layout' => [], 'seo' => [], 'excerpt' => '', 'faq' => []];
        if (! empty($data['location_id']) && $data['type'] !== 'branch') {
            throw ValidationException::withMessages(['location_id' => 'Only a physical branch page can reference a business location.']);
        }
        if (isset($data['home_sections'])) {
            if ($data['type'] !== 'home') {
                throw ValidationException::withMessages(['home_sections' => 'Homepage sections are available only on the homepage.']);
            }
            $data['home_sections'] = $this->homepage->validate($data['home_sections'], $includeDraft);
        }
        $data['seo'] = array_intersect_key($data['seo'], array_flip(['title', 'description', 'canonical', 'robots', 'og_title', 'og_description', 'og_image', 'og_image_alt', 'twitter_card', 'schema_mode', 'schema_type', 'schema_custom']));
        $this->renderer($includeDraft)->render($data['content']);
        $this->layouts->render($data['layout']);
        $this->validateLayoutMedia($data['layout'], $includeDraft);
        if (! empty($data['seo']['og_image'])) {
            if (! preg_match('~^/files/([a-zA-Z0-9_-]+)$~D', $data['seo']['og_image'], $matches)) {
                throw ValidationException::withMessages(['seo.og_image' => 'Select a local publishing image.']);
            }
            $this->renderer($includeDraft)->render([['type' => 'image', 'props' => ['mediaId' => $matches[1]]]]);
        }
        if (! empty($data['seo']['schema_custom'])) {
            $schema = $data['seo']['schema_custom'];
            $encoded = json_encode($schema, JSON_THROW_ON_ERROR);
            if (strlen($encoded) > 16000 || ! in_array($schema['@type'] ?? '', ['WebPage', 'AboutPage', 'ContactPage', 'CollectionPage', 'Article', 'BlogPosting', 'WebApplication'], true) || isset($schema['@graph']) || isset($schema['@context']) && ! in_array($schema['@context'], ['https://schema.org', 'https://schema.org/'], true)) {
                throw ValidationException::withMessages(['seo.schema_custom' => 'Use a bounded page schema object with a supported @type and schema.org context.']);
            }
            $allowed = ['@type', '@context', 'description', 'keywords', 'name', 'inLanguage', 'alternativeHeadline', 'abstract', 'applicationCategory', 'operatingSystem'];
            if (array_diff(array_keys($schema), $allowed)) {
                throw ValidationException::withMessages(['seo.schema_custom' => 'Custom schema supports descriptive page properties; business IDs, reviews, ratings, and nested entities are application controlled.']);
            }
            foreach ($schema as $value) {
                if (! is_string($value) || strlen($value) > 3000) {
                    throw ValidationException::withMessages(['seo.schema_custom' => 'Custom schema descriptive properties must be text.']);
                }
            }
        }

        return $data;
    }

    private function validateLocation(array $page, ?string $pageId, bool $publishing): void
    {
        $this->records->lock('website-settings:main');
        $locations = app(WebsiteSettings::class)->publicData(! $publishing)['locations'];
        $explicitId = $page['location_id'] ?? null;
        $associated = $pageId ? collect($locations)->firstWhere('page_id', $pageId) : null;
        if ($associated && (($page['type'] ?? '') !== 'branch' || ($explicitId && $explicitId !== $associated['id']))) {
            throw ValidationException::withMessages(['location_id' => 'This page is associated with another physical location. Update website location settings before changing its branch identity.']);
        }
        $locationId = $explicitId ?: ($associated['id'] ?? null);
        if (! $locationId) {
            return;
        }
        $location = collect($locations)->firstWhere('id', $locationId);
        if (! $location || (! empty($location['page_id']) && $location['page_id'] !== $pageId)) {
            throw ValidationException::withMessages(['location_id' => $publishing ? 'Select a published physical location that is not associated with another page.' : 'Select an existing physical location that is not associated with another page.']);
        }
        foreach ($this->records->all('pages') as $other) {
            if ($other['id'] === $pageId || ($other['status'] ?? '') === 'archived') {
                continue;
            }
            foreach ([$other, $other['published'] ?? []] as $revision) {
                if (($revision['location_id'] ?? null) === $locationId) {
                    throw ValidationException::withMessages(['location_id' => 'Another page already references this physical location. Clear that association before assigning a replacement page.']);
                }
            }
        }
    }

    private function validateLayoutMedia(array $value, bool $includeDraft): void
    {
        if (isset($value['attributes']['src'])) {
            $this->renderer($includeDraft)->render([['type' => 'image', 'props' => ['url' => $value['attributes']['src']]]]);
        }
        foreach ($value as $child) {
            if (is_array($child)) {
                $this->validateLayoutMedia($child, $includeDraft);
            }
        }
    }

    /**
     * Assets remain public after their first publication. Unpublishing a page
     * cannot retract copies already downloaded or break shared published assets.
     */
    private function promoteDraftMedia(array $page, string $pageId): array
    {
        $ids = [];
        $collect = function (array $node) use (&$collect, &$ids): void {
            if (($node['type'] ?? '') === 'image') {
                if (is_string($node['props']['mediaId'] ?? null)) {
                    $ids[] = $node['props']['mediaId'];
                } elseif (preg_match('~^/files/([a-zA-Z0-9_-]+)$~D', $node['props']['url'] ?? '', $matches)) {
                    $ids[] = $matches[1];
                }
            }
            if (preg_match('~^/files/([a-zA-Z0-9_-]+)$~D', $node['attributes']['src'] ?? '', $matches)) {
                $ids[] = $matches[1];
            }
            foreach ($node as $child) {
                if (is_array($child)) {
                    $collect($child);
                }
            }
        };
        $collect($page['content'] ?? []);
        $collect($page['layout'] ?? []);
        $ids = array_merge($ids, $this->homepage->mediaIds($page['home_sections'] ?? []));
        if (preg_match('~^/files/([a-zA-Z0-9_-]+)$~D', $page['seo']['og_image'] ?? '', $matches)) {
            $ids[] = $matches[1];
        }
        $ids = array_values(array_unique($ids));
        foreach ($ids as $id) {
            $file = $this->records->get('files', $id);
            if ($file['visibility'] === 'draft') {
                $this->records->update('files', $id, [
                    'visibility' => 'public', 'first_published_at' => now()->toIso8601String(),
                    'publication_policy' => 'permanent-after-publication', 'first_published_page_id' => $pageId,
                ], $file['version']);
            }
        }

        return $ids;
    }

    private function uniqueSlug(string $slug, ?string $except = null): void
    {
        foreach ($this->records->all('pages') as $page) {
            if ($page['id'] !== $except && ($page['slug'] === $slug || ($page['published']['slug'] ?? '') === $slug)) {
                throw ValidationException::withMessages(['slug' => 'This slug is already in use.']);
            }
        }
        $redirect = $this->records->find('redirects', hash('sha256', $slug));
        if ($redirect && $redirect['page_id'] !== $except) {
            throw ValidationException::withMessages(['slug' => 'This slug is reserved by an existing redirect.']);
        }
    }

    private function revision(array $page, string $author, string $reason): void
    {
        $snapshot = array_diff_key($page, array_flip(['published']));
        $this->records->create('page_revisions', ['page_id' => $page['id'], 'page_version' => $page['version'], 'author_id' => $author, 'reason' => $reason, 'snapshot' => $snapshot]);
    }
}

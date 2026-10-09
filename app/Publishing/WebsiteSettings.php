<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace App\Publishing;

use App\Contracts\RecordRepository;
use App\Support\Outbox;
use App\Support\Settings;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class WebsiteSettings
{
    public const FONTS = ['inter', 'manrope', 'montserrat', 'source-sans-3', 'open-sans', 'work-sans', 'lora', 'merriweather'];

    public const DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

    private const PROFILE_KEYS = ['name', 'trade', 'phone', 'email', 'address_visibility', 'address', 'city', 'region', 'postal_code', 'country', 'timezone', 'service_areas', 'hours', 'special_hours', 'profile_urls'];

    public function __construct(private readonly RecordRepository $records) {}

    public function defaults(): array
    {
        $business = Settings::get('business');
        $profile = ['name' => '', 'trade' => '', 'phone' => '', 'email' => '', 'address_visibility' => 'hidden', 'address' => '', 'city' => '', 'region' => '', 'postal_code' => '', 'country' => 'US', 'timezone' => 'America/New_York', 'service_areas' => [], 'hours' => [], 'special_hours' => [], 'profile_urls' => []];
        foreach (['name', 'trade', 'phone', 'email'] as $key) {
            $profile[$key] = is_string($business[$key] ?? null) ? mb_substr(strip_tags($business[$key]), 0, $key === 'phone' ? 40 : 180) : '';
        }
        if (in_array($business['country'] ?? '', ['US', 'CA'], true)) {
            $profile['country'] = $business['country'];
        }
        if (in_array($business['timezone'] ?? '', \DateTimeZone::listIdentifiers(), true)) {
            $profile['timezone'] = $business['timezone'];
        }
        $areas = is_array($business['service_areas'] ?? null) ? $business['service_areas'] : [];
        $profile['service_areas'] = array_slice(array_values(array_filter($areas, 'is_string')), 0, 50);

        return [
            'schema_version' => 1,
            'appearance' => [
                'colors' => ['primary' => '#173E39', 'accent' => '#BAE4BD', 'background' => '#FAFAF7', 'surface' => '#FFFFFF', 'text' => '#172926', 'muted' => '#536760', 'border' => '#DDE4DF'],
                'heading_font_id' => 'manrope', 'body_font_id' => 'inter', 'type_scale' => 'standard', 'button_radius' => 'soft',
                'content_width' => 'standard', 'section_spacing' => 'standard', 'logo_media_id' => null, 'favicon_media_id' => null,
            ],
            'navigation' => ['primary' => [], 'footer' => [], 'header_button' => ['label' => 'Request service', 'kind' => 'anchor', 'anchor' => 'request-service', 'style' => 'primary'], 'show_client_portal' => true],
            'profile' => $profile, 'locations' => [],
        ];
    }

    public function get(): array
    {
        $existing = $this->records->find('website_settings', 'main');
        if ($existing) {
            return $existing;
        }

        return $this->records->transaction(function (): array {
            $this->records->lock('website-settings:main');

            return $this->records->find('website_settings', 'main') ?? $this->records->create('website_settings', [
                'draft' => $this->defaults(), 'published' => null, 'published_version' => null, 'has_unpublished_changes' => true,
            ], 'main');
        });
    }

    public function update(array $draft, int $expected, string $author): array
    {
        return $this->records->transaction(function () use ($draft, $expected, $author): array {
            $this->records->lock('website-settings:main');
            $current = $this->get();
            $this->checkVersion($current, $expected);
            $validated = $this->validate($draft);
            $this->revision($current, $author, 'edit');
            Outbox::audit('website.settings_updated', ['website_version' => $current['version']]);

            return $this->records->update('website_settings', 'main', ['draft' => $validated, 'has_unpublished_changes' => ! $this->matchesPublished($validated, $current['published'] ?? null)], $expected);
        });
    }

    public function publish(int $expected, string $author): array
    {
        return $this->records->transaction(function () use ($expected, $author): array {
            $this->records->lock('website-settings:main');
            $current = $this->get();
            $this->checkVersion($current, $expected);
            $snapshot = $this->validate($current['draft'], true);
            $this->revision($current, $author, 'publish');
            foreach (['logo_media_id', 'favicon_media_id'] as $key) {
                if (! empty($snapshot['appearance'][$key])) {
                    $file = $this->records->get('files', $snapshot['appearance'][$key]);
                    if ($file['visibility'] === 'draft') {
                        $this->records->update('files', $file['id'], ['visibility' => 'public', 'first_published_at' => now()->toIso8601String(), 'publication_policy' => 'permanent-after-publication', 'first_published_website_version' => $expected + 1], $file['version']);
                    }
                }
            }
            Outbox::audit('website.settings_published', ['website_version' => $expected + 1]);

            return $this->records->update('website_settings', 'main', ['published' => $snapshot, 'published_version' => $expected + 1, 'published_at' => now()->toIso8601String(), 'has_unpublished_changes' => false], $expected);
        });
    }

    public function revisions(): array
    {
        $revisions = $this->records->all('website_revisions', ['website_id' => 'main']);
        usort($revisions, fn (array $a, array $b): int => [$b['website_version'], $b['created_at']] <=> [$a['website_version'], $a['created_at']]);

        return $revisions;
    }

    public function restore(string $id, int $expected, string $author): array
    {
        return $this->records->transaction(function () use ($id, $expected, $author): array {
            $this->records->lock('website-settings:main');
            $current = $this->get();
            $this->checkVersion($current, $expected);
            $revision = $this->records->get('website_revisions', $id);
            abort_unless($revision['website_id'] === 'main', 404);
            $draft = $this->validate($revision['snapshot']);
            $this->revision($current, $author, 'restore');
            Outbox::audit('website.revision_restored', ['revision_id' => $id]);

            return $this->records->update('website_settings', 'main', ['draft' => $draft, 'has_unpublished_changes' => ! $this->matchesPublished($draft, $current['published'] ?? null)], $expected);
        });
    }

    public function publicData(bool $preview = false): array
    {
        $record = $this->records->find('website_settings', 'main');
        $data = ($preview ? ($record['draft'] ?? null) : ($record['published'] ?? null)) ?? $this->defaults();
        $data['profile'] = $this->publicProfile($data['profile']);
        $data['locations'] = array_map(fn (array $location): array => $this->publicProfile($location), $data['locations']);

        return $data;
    }

    public function business(bool $preview = false, ?string $locationId = null): array
    {
        $data = $this->publicData($preview);
        $profile = $data['profile'];
        if ($locationId && $locationId !== 'primary') {
            $profile = collect($data['locations'])->firstWhere('id', $locationId) ?? [];
        }

        return Arr::only($profile, [...self::PROFILE_KEYS, 'id', 'page_id']) + ['location_id' => $profile['id'] ?? null];
    }

    public function publicProfile(array $profile): array
    {
        $safe = Arr::only($profile, [...self::PROFILE_KEYS, 'id', 'page_id']);
        if (($safe['address_visibility'] ?? 'hidden') !== 'visible') {
            $safe = Arr::except($safe, ['address', 'city', 'region', 'postal_code']);
        }

        return $safe;
    }

    public function validate(array $draft, bool $publishing = false): array
    {
        if (strlen(json_encode($draft, JSON_THROW_ON_ERROR)) > 100000) {
            throw ValidationException::withMessages(['draft' => 'Website settings exceed the document size limit.']);
        }
        $rules = [
            'draft' => ['required', 'array:schema_version,appearance,navigation,profile,locations'],
            'draft.schema_version' => ['sometimes', 'integer', 'in:1'],
            'draft.appearance' => ['required', 'array:colors,heading_font_id,body_font_id,type_scale,button_radius,content_width,section_spacing,logo_media_id,favicon_media_id'],
            'draft.appearance.colors' => ['required', 'array:primary,accent,background,surface,text,muted,border'],
            'draft.appearance.heading_font_id' => ['required', Rule::in(self::FONTS)],
            'draft.appearance.body_font_id' => ['required', Rule::in(self::FONTS)],
            'draft.appearance.type_scale' => ['required', Rule::in(['compact', 'standard', 'large'])],
            'draft.appearance.button_radius' => ['required', Rule::in(['square', 'soft', 'rounded'])],
            'draft.appearance.content_width' => ['required', Rule::in(['standard', 'wide'])],
            'draft.appearance.section_spacing' => ['required', Rule::in(['compact', 'standard', 'generous'])],
            'draft.appearance.logo_media_id' => ['nullable', 'string', 'max:128', 'regex:/^[a-zA-Z0-9_-]+$/D'],
            'draft.appearance.favicon_media_id' => ['nullable', 'string', 'max:128', 'regex:/^[a-zA-Z0-9_-]+$/D'],
            'draft.navigation' => ['required', 'array:primary,footer,header_button,show_client_portal'],
            'draft.navigation.primary' => ['present', 'array', 'list', 'max:8'],
            'draft.navigation.footer' => ['present', 'array', 'list', 'max:20'],
            'draft.navigation.header_button' => ['nullable', 'array:label,kind,page_id,url,anchor,style'],
            'draft.navigation.show_client_portal' => ['required', 'boolean'],
            'draft.profile' => ['required', 'array:'.implode(',', self::PROFILE_KEYS)],
            'draft.locations' => ['present', 'array', 'list', 'max:20'],
            'draft.locations.*' => ['array:'.implode(',', [...self::PROFILE_KEYS, 'id', 'page_id'])],
            'draft.locations.*.id' => ['required', 'string', 'max:80', 'distinct:strict', 'not_in:primary', 'regex:/^[a-zA-Z0-9_-]+$/D'],
            'draft.locations.*.page_id' => ['nullable', 'string', 'max:128'],
        ];
        foreach (['primary', 'accent', 'background', 'surface', 'text', 'muted', 'border'] as $color) {
            $rules['draft.appearance.colors.'.$color] = ['required', 'string', 'regex:/^#[a-fA-F0-9]{6}$/D'];
        }
        foreach (['draft.profile', 'draft.locations.*'] as $prefix) {
            $rules += $this->profileRules($prefix);
        }
        $data = Validator::make(['draft' => $draft], $rules)->validate()['draft'];
        $data['schema_version'] = 1;
        foreach (['primary', 'footer'] as $group) {
            foreach ($data['navigation'][$group] as $index => $link) {
                $data['navigation'][$group][$index] = $this->validateLink($link, false, $publishing, 'draft.navigation.'.$group.'.'.$index);
            }
        }
        if (! empty($data['navigation']['header_button'])) {
            $data['navigation']['header_button'] = $this->validateLink($data['navigation']['header_button'], true, $publishing, 'draft.navigation.header_button');
        }
        foreach (['logo_media_id', 'favicon_media_id'] as $key) {
            if (! empty($data['appearance'][$key])) {
                $file = $this->records->find('files', $data['appearance'][$key]);
                if (! $file || ! in_array($file['visibility'] ?? '', ['public', 'draft'], true) || ($file['status'] ?? '') !== 'ready' || ! in_array($file['mime'] ?? '', ['image/png', 'image/jpeg', 'image/webp'], true) || ! empty($file['resource_type']) || ($file['initial_visibility'] ?? '') === 'private') {
                    throw ValidationException::withMessages(['draft.appearance.'.$key => 'Select a ready local publishing image. Private files cannot become website branding.']);
                }
            }
        }
        $this->validateProfile($data['profile'], 'draft.profile', $publishing);
        $linkedPageIds = [];
        foreach ($data['locations'] as $index => $location) {
            $this->validateProfile($location, 'draft.locations.'.$index, $publishing);
            $data['locations'][$index] = $location;
            if (! empty($location['page_id'])) {
                $field = 'draft.locations.'.$index.'.page_id';
                if (in_array($location['page_id'], $linkedPageIds, true)) {
                    throw ValidationException::withMessages([$field => 'Each branch page can belong to only one physical location.']);
                }
                $linkedPageIds[] = $location['page_id'];
                $page = $this->validPage($location['page_id'], $publishing, $field);
                if (($publishing ? $page['published']['type'] : $page['type']) !== 'branch') {
                    throw ValidationException::withMessages([$field => 'Associate an actual branch page with this physical location.']);
                }
                foreach ([$page, $page['published'] ?? []] as $revision) {
                    if (! empty($revision['location_id']) && $revision['location_id'] !== $location['id']) {
                        throw ValidationException::withMessages([$field => 'This page or its published revision belongs to a different physical location. Update that page association first.']);
                    }
                }
            }
        }

        return $data;
    }

    private function profileRules(string $prefix): array
    {
        $rules = [
            $prefix.'.name' => ['present', 'nullable', 'string', 'max:180'], $prefix.'.trade' => ['present', 'nullable', 'string', 'max:80'],
            $prefix.'.phone' => ['present', 'nullable', 'string', 'max:40', 'regex:/^[+0-9().\s-]*$/D'],
            $prefix.'.email' => ['present', 'nullable', 'email:rfc', 'max:254'],
            $prefix.'.address_visibility' => ['required', Rule::in(['visible', 'hidden'])],
            $prefix.'.country' => ['required', Rule::in(['US', 'CA'])], $prefix.'.timezone' => ['required', 'timezone:all'],
            $prefix.'.service_areas' => ['present', 'array', 'list', 'max:50'], $prefix.'.service_areas.*' => ['required', 'string', 'max:150'],
            $prefix.'.hours' => ['present', 'array', 'list', 'max:7'], $prefix.'.hours.*' => ['array:day,opens,closes,closed'],
            $prefix.'.hours.*.day' => ['required', Rule::in(self::DAYS)],
            $prefix.'.special_hours' => ['present', 'array', 'list', 'max:50'], $prefix.'.special_hours.*' => ['array:date,opens,closes,closed'],
            $prefix.'.special_hours.*.date' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before:2101-01-01'],
            $prefix.'.profile_urls' => ['present', 'array', 'list', 'max:12'], $prefix.'.profile_urls.*' => ['array:label,url'],
            $prefix.'.profile_urls.*.label' => ['required', 'string', 'max:80'], $prefix.'.profile_urls.*.url' => ['required', 'url:https', 'max:2000'],
        ];
        foreach (['address' => 250, 'city' => 150, 'region' => 100, 'postal_code' => 20] as $key => $length) {
            $rules[$prefix.'.'.$key] = ['present', 'nullable', 'string', 'max:'.$length];
        }
        foreach (['hours', 'special_hours'] as $group) {
            $rules[$prefix.'.'.$group.'.*.closed'] = ['required', 'boolean'];
            foreach (['opens', 'closes'] as $key) {
                $rules[$prefix.'.'.$group.'.*.'.$key] = ['nullable', 'date_format:H:i'];
            }
        }

        return $rules;
    }

    private function validateProfile(array &$profile, string $path, bool $publishing): void
    {
        foreach (['name', 'trade', 'phone', 'email', 'address', 'city', 'region', 'postal_code'] as $key) {
            $profile[$key] = trim($profile[$key] ?? '');
        }
        $this->cleanStrings($profile, $path);
        if ($profile['phone'] && ! preg_match('/^\d{7,15}$/D', preg_replace('/\D/', '', $profile['phone']))) {
            throw ValidationException::withMessages([$path.'.phone' => 'Enter a public telephone number with 7–15 digits.']);
        }
        if ($publishing && $profile['name'] === '') {
            throw ValidationException::withMessages([$path.'.name' => 'Enter the real public business name before publication.']);
        }
        if ($publishing && $profile['address_visibility'] === 'visible') {
            foreach (['address', 'city', 'region', 'postal_code'] as $field) {
                if ($profile[$field] === '') {
                    throw ValidationException::withMessages([$path.'.'.$field => 'Complete the actual public address or keep it hidden.']);
                }
            }
        }
        $areas = array_map(fn (string $area): string => mb_strtolower(trim($area)), $profile['service_areas']);
        if (count(array_unique($areas)) !== count($areas)) {
            throw ValidationException::withMessages([$path.'.service_areas' => 'List each service area only once.']);
        }
        foreach (['hours' => 'day', 'special_hours' => 'date'] as $group => $key) {
            $seen = [];
            foreach ($profile[$group] as $index => $hours) {
                if (in_array($hours[$key], $seen, true)) {
                    throw ValidationException::withMessages([$path.'.'.$group.'.'.$index.'.'.$key => 'Each day or date can appear only once.']);
                }
                $seen[] = $hours[$key];
                if (! $hours['closed'] && (empty($hours['opens']) || empty($hours['closes']) || $hours['opens'] === $hours['closes'])) {
                    throw ValidationException::withMessages([$path.'.'.$group.'.'.$index => 'Open hours need different opening and closing times; an earlier closing time means overnight service.']);
                }
            }
        }
        foreach ($profile['profile_urls'] as $index => $link) {
            $this->safeUrl($link['url'], $path.'.profile_urls.'.$index.'.url', false);
        }
    }

    private function validateLink(mixed $link, bool $button, bool $publishing, string $path): array
    {
        if (! is_array($link)) {
            throw ValidationException::withMessages([$path => 'A navigation link must be an object.']);
        }
        $data = Validator::make(['link' => $link], [
            'link' => ['required', 'array:label,kind,page_id,url'.($button ? ',anchor,style' : '')],
            'link.label' => ['required', 'string', 'max:80'], 'link.kind' => ['required', Rule::in($button ? ['page', 'url', 'phone', 'anchor'] : ['page', 'url'])],
            'link.page_id' => ['nullable', 'string', 'max:128'], 'link.url' => ['nullable', 'string', 'max:2000'],
            'link.anchor' => ['nullable', 'string', 'regex:/^[a-z][a-z0-9_-]{0,80}$/D'], 'link.style' => ['sometimes', Rule::in(['primary', 'secondary', 'text'])],
        ])->validate()['link'];
        $this->cleanStrings($data, $path);
        if ($data['kind'] === 'page') {
            $this->validPage($data['page_id'] ?? '', $publishing, $path.'.page_id');
        } elseif ($data['kind'] === 'url') {
            $this->safeUrl($data['url'] ?? '', $path.'.url');
        } elseif ($data['kind'] === 'anchor') {
            if (empty($data['anchor'])) {
                throw ValidationException::withMessages([$path.'.anchor' => 'Select a section anchor.']);
            }
            if ($publishing && ! $this->hasHomepageAnchor($data['anchor'])) {
                throw ValidationException::withMessages([$path.'.anchor' => 'Choose an enabled section on the published homepage before publishing this header button.']);
            }
        }

        return Arr::only($data, ['label', 'kind', match ($data['kind']) {
            'page' => 'page_id', 'url' => 'url', 'anchor' => 'anchor', default => 'kind'
        }, ...($button ? ['style'] : [])]);
    }

    public function hasHomepageAnchor(string $anchor): bool
    {
        $homepage = null;
        foreach ($this->records->all('pages', ['status' => 'published']) as $page) {
            if (($page['published']['type'] ?? '') === 'home') {
                $homepage = $page['published'];
                break;
            }
        }
        $isContact = in_array($anchor, ['contact', 'request-service'], true);
        if (($homepage['home_sections']['mode'] ?? '') !== 'guided') {
            return $isContact;
        }
        foreach ($homepage['home_sections']['sections'] ?? [] as $section) {
            if (! empty($section['enabled']) && ($isContact ? $section['type'] === 'contact' : $section['id'] === $anchor)) {
                return true;
            }
        }

        return false;
    }

    private function validPage(string $id, bool $publishing, string $path): array
    {
        $page = $this->records->find('pages', $id);
        if (! $page || ($page['status'] ?? '') === 'archived' || ($publishing && (! is_array($page['published'] ?? null) || ($page['status'] ?? '') !== 'published'))) {
            throw ValidationException::withMessages([$path => $publishing ? 'Publish the linked page before publishing website settings.' : 'Select an existing, non-archived page.']);
        }

        return $page;
    }

    private function safeUrl(string $url, string $path, bool $relative = true): void
    {
        $local = $relative && preg_match('~^/(?!/)[a-zA-Z0-9/_?#=&%.,:+-]*$~D', $url);
        $parts = parse_url($url);
        if (preg_match('/[\x00-\x20\x7f\\\\]/', $url) || (! $local && (! filter_var($url, FILTER_VALIDATE_URL) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass'])))) {
            throw ValidationException::withMessages([$path => 'Use a safe HTTP(S) URL or local page path without credentials.']);
        }
    }

    private function cleanStrings(array $data, string $path): void
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $this->cleanStrings($value, $path.'.'.$key);
            } elseif (is_string($value) && (strip_tags($value) !== $value || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value))) {
                throw ValidationException::withMessages([$path.'.'.$key => 'Use plain text without markup or control characters.']);
            }
        }
    }

    private function checkVersion(array $current, int $expected): void
    {
        abort_unless($current['version'] === $expected, 409, 'Website settings changed. Reload the latest version before saving.');
    }

    private function matchesPublished(array $draft, ?array $published): bool
    {
        return $published !== null && $this->canonicalValues($draft) === $this->canonicalValues($published);
    }

    private function canonicalValues(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $child) {
            $value[$key] = $this->canonicalValues($child);
        }

        return $value;
    }

    private function revision(array $current, string $author, string $reason): void
    {
        $this->records->create('website_revisions', ['website_id' => 'main', 'website_version' => $current['version'], 'author_id' => $author, 'reason' => $reason, 'snapshot' => $current['draft']]);
    }
}

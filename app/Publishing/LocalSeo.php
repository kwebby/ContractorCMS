<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace App\Publishing;

use App\Contracts\RecordRepository;
use App\Support\Outbox;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class LocalSeo
{
    public function __construct(private readonly RecordRepository $records, private readonly WebsiteSettings $website) {}

    public function citations(): array
    {
        $settings = $this->website->get();
        $public = $this->website->publicData();
        $citations = array_map(fn (array $row): array => $this->comparison($row, $settings, $public), $this->records->all('citation_observations'));
        usort($citations, fn (array $a, array $b): int => [$a['location_id'], $a['provider'], $a['id']] <=> [$b['location_id'], $b['provider'], $b['id']]);

        return $citations;
    }

    public function save(array $input, string $author, ?string $id = null): array
    {
        $data = Validator::make($input, [
            'location_id' => ['required', 'string', 'max:80'], 'provider' => ['required', 'string', 'max:120'],
            'listing_url' => ['required', 'url:http,https', 'max:2000'],
            'observed_name' => ['nullable', 'string', 'max:180'], 'observed_address' => ['nullable', 'string', 'max:600'],
            'observed_phone' => ['nullable', 'string', 'max:80'], 'observed_website' => ['nullable', 'url:http,https', 'max:2000'],
            'checked_at' => ['nullable', 'date', 'before_or_equal:now', 'after_or_equal:2000-01-01'],
            'notes' => ['nullable', 'string', 'max:3000'],
            'correction_status' => ['required', Rule::in(['none', 'assigned', 'submitted', 'awaiting_review', 'corrected', 'rechecked'])],
            'correction_owner' => ['nullable', 'string', 'max:128'],
            'expected_version' => [$id ? 'required' : 'prohibited', 'integer', 'min:1'],
        ])->validate();
        foreach (['listing_url', 'observed_website'] as $key) {
            $url = $data[$key] ?? '';
            $parts = parse_url($url);
            if ($url && (isset($parts['user']) || isset($parts['pass']) || preg_match('/[\x00-\x20\x7f\\\\]/', $url))) {
                throw ValidationException::withMessages([$key => 'Use a public HTTP(S) listing URL without credentials.']);
            }
        }
        foreach ($data as $key => $value) {
            if (is_string($value) && preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
                throw ValidationException::withMessages([$key => 'Control characters are not supported.']);
            }
        }
        $expected = Arr::pull($data, 'expected_version');
        $data += ['observed_name' => null, 'observed_address' => null, 'observed_phone' => null, 'observed_website' => null, 'checked_at' => null, 'notes' => null, 'correction_owner' => null];
        if ($data['checked_at']) {
            $data['checked_at'] = CarbonImmutable::parse($data['checked_at'])->toIso8601String();
        }
        if (! empty($data['correction_owner'])) {
            $owner = $this->records->find('users', $data['correction_owner']);
            if (! $owner || ($owner['role'] ?? '') === 'client' || ! ($owner['active'] ?? true)) {
                throw ValidationException::withMessages(['correction_owner' => 'Select an active staff member for the correction.']);
            }
        }

        return $this->records->transaction(function () use ($data, $author, $id, $expected): array {
            $this->records->lock('website-settings:main');
            if ($id) {
                $this->records->lock('citation:'.$id);
            }
            $settings = $this->website->get();
            $public = $this->website->publicData();
            if ($data['location_id'] !== 'primary' && ! collect($public['locations'])->contains('id', $data['location_id'])) {
                throw ValidationException::withMessages(['location_id' => 'Publish this physical branch before tracking its listings.']);
            }
            $existing = $id ? $this->records->get('citation_observations', $id) : null;
            if ($existing) {
                abort_unless($existing['version'] === $expected, 409, 'This citation changed. Reload before saving.');
            }
            $evidenceKeys = ['location_id', 'listing_url', 'observed_name', 'observed_address', 'observed_phone', 'observed_website', 'checked_at'];
            $evidenceChanged = ! $existing;
            foreach ($evidenceKeys as $key) {
                if (($existing[$key] ?? null) !== ($data[$key] ?? null)) {
                    $evidenceChanged = true;
                    break;
                }
            }
            $metadata = $evidenceChanged
                ? ['checked_by' => $data['checked_at'] ? $author : null, 'canonical_revision' => $settings['published_version'], 'provenance' => 'manual']
                : Arr::only($existing, ['checked_by', 'canonical_revision', 'provenance']);
            $saved = $id ? $this->records->update('citation_observations', $id, $data + $metadata, $expected) : $this->records->create('citation_observations', $data + $metadata);
            Outbox::audit('website.citation_'.($id ? 'updated' : 'created'), ['citation_id' => $saved['id']]);

            return $this->comparison($saved, $settings, $public);
        });
    }

    public function dashboard(): array
    {
        $settings = $this->website->get();
        $public = $this->website->publicData();
        $citations = $this->citations();
        $pages = $this->records->all('pages');
        $diagnostics = [];
        $add = function (string $code, string $message, string $path, string $severity = 'warning') use (&$diagnostics): void {
            $diagnostics[] = compact('code', 'severity', 'message', 'path');
        };
        if (! $settings['published']) {
            $add('profile_unpublished', 'Publish website settings to establish a canonical public profile.', '/app/website/local-seo/business-profile');
        }
        foreach (['primary' => $public['profile'], ...collect($public['locations'])->keyBy('id')->all()] as $id => $profile) {
            $profilePath = $id === 'primary' ? '/app/website/local-seo/business-profile' : '/app/website/local-seo/locations/'.rawurlencode($id);
            foreach (['name', 'phone'] as $key) {
                if (empty($profile[$key])) {
                    $add('missing_'.$key, 'The '.$id.' public profile has no '.$key.'.', $profilePath);
                }
            }
            if (! $profile['hours']) {
                $add('missing_hours', 'Add accurate operating hours for '.$id.'.', $profilePath, 'info');
            }
            if (($profile['address_visibility'] ?? 'hidden') === 'hidden') {
                $add('address_hidden', 'Precise address details for '.$id.' are excluded from public output.', $profilePath, 'info');
            }
            if (! $profile['profile_urls']) {
                $add('missing_profile_links', 'Add verified public business profile links for '.$id.'.', $profilePath, 'info');
            }
        }
        $colors = $public['appearance']['colors'];
        foreach ([[$colors['text'], $colors['background'], 'Body text'], [$colors['muted'], $colors['background'], 'Secondary text'], ['#FFFFFF', $colors['primary'], 'Primary buttons']] as [$foreground, $background, $label]) {
            $ratio = $this->contrast($foreground, $background);
            if ($ratio < 4.5) {
                $add('low_contrast', $label.' contrast is '.round($ratio, 2).':1. Aim for at least 4.5:1 for normal-size text.', '/app/website/appearance');
            }
        }
        foreach (array_merge($public['navigation']['primary'], $public['navigation']['footer'], empty($public['navigation']['header_button']) ? [] : [$public['navigation']['header_button']]) as $link) {
            if (($link['kind'] ?? '') === 'page') {
                $linked = collect($pages)->firstWhere('id', $link['page_id']);
                if (! $linked || ($linked['status'] ?? '') !== 'published' || empty($linked['published'])) {
                    $add('unpublished_navigation', 'Navigation destination “'.$link['label'].'” is unavailable publicly.', '/app/website/navigation');
                }
            }
            if (($link['kind'] ?? '') === 'phone' && empty($public['profile']['phone'])) {
                $add('missing_phone_destination', 'The header call button needs a canonical public phone number.', '/app/website/local-seo/business-profile');
            }
            if (($link['kind'] ?? '') === 'anchor' && ! $this->website->hasHomepageAnchor($link['anchor'] ?? '')) {
                $add('missing_header_anchor', 'The header button no longer points to an enabled published homepage section.', '/app/website/navigation');
            }
        }
        foreach ($public['locations'] as $location) {
            $linked = ! empty($location['page_id']) ? collect($pages)->firstWhere('id', $location['page_id']) : null;
            if (! $linked || ($linked['status'] ?? '') !== 'published' || ($linked['published']['type'] ?? '') !== 'branch') {
                $add('branch_page_missing', 'Physical branch “'.$location['name'].'” has no published branch page.', '/app/website/local-seo/locations/'.rawurlencode($location['id']), 'info');
            }
        }
        foreach ($pages as $page) {
            if (($page['status'] ?? '') !== 'published' || ($page['published']['type'] ?? '') !== 'branch') {
                continue;
            }
            $explicitId = $page['published']['location_id'] ?? null;
            $associated = collect($public['locations'])->firstWhere('page_id', $page['id']);
            $location = $explicitId ? collect($public['locations'])->firstWhere('id', $explicitId) : $associated;
            if (! $location || ($associated && $associated['id'] !== $location['id']) || (! empty($location['page_id']) && $location['page_id'] !== $page['id'])) {
                $add('branch_profile_missing', 'Branch page “'.$page['published']['title'].'” needs an unambiguous published physical location profile.', '/app/pages?edit='.$page['id']);
            }
        }
        $coverage = [];
        foreach ([
            'home' => ['Homepage', 'Publish one homepage with useful service and contact paths.'],
            'service' => ['Services', 'Describe the services the business actually performs.'],
            'about' => ['About / team', 'Use truthful experience, team details and credentials.'],
            'contact' => ['Contact', 'Provide canonical contact details and a request path.'],
            'branch' => ['Physical branches', 'Create pages only for actual staffed locations.'],
            'service-area' => ['Service areas', 'Use original local evidence; a served city is not a branch.'],
            'case-study' => ['Projects / case studies', 'Show permissioned original work and outcomes.'],
            'faq' => ['FAQs', 'Answer real questions about the company and services.'],
            'article' => ['Resources / articles', 'Offer reviewed, helpful information for customers.'],
            'careers' => ['Careers', 'Describe genuine hiring opportunities.'],
            'tool' => ['Lead tools', 'Offer a useful tool only when configured and available.'],
            'page' => ['Other pages / policies', 'Add applicable financing, maintenance, privacy and terms pages.'],
        ] as $type => [$label, $description]) {
            $matching = array_values(array_filter($pages, fn (array $page): bool => ($page['type'] ?? '') === $type || ($page['published']['type'] ?? '') === $type));
            $published = count(array_filter($matching, fn (array $page): bool => ($page['status'] ?? '') === 'published' && ($page['published']['type'] ?? '') === $type));
            $drafts = count(array_filter($matching, fn (array $page): bool => ($page['status'] ?? '') !== 'archived' && ($page['type'] ?? '') === $type && (! empty($page['has_unpublished_changes']) || ($page['status'] ?? '') === 'draft')));
            $coverage[] = ['type' => $type, 'label' => $label, 'description' => $description, 'published_count' => $published, 'draft_count' => $drafts, 'pages' => array_map(fn (array $page): array => ['id' => $page['id'], 'title' => $page['title'], 'status' => $page['status'], 'edit_url' => '/app/pages?edit='.$page['id']], $matching)];
        }

        return [
            'profile' => $public['profile'], 'locations' => $public['locations'], 'diagnostics' => $diagnostics, 'coverage' => $coverage,
            'counts' => [
                'published_pages' => count(array_filter($pages, fn (array $page): bool => ($page['status'] ?? '') === 'published' && ! empty($page['published']))),
                'draft_pages' => count(array_filter($pages, fn (array $page): bool => ($page['status'] ?? '') !== 'archived' && (($page['status'] ?? '') === 'draft' || ! empty($page['has_unpublished_changes'])))),
                'citations' => count($citations), 'needs_review' => count(array_filter($citations, fn (array $row): bool => in_array($row['comparison']['status'], ['difference', 'needs_review'], true))),
                'stale' => count(array_filter($citations, fn (array $row): bool => $row['stale'])),
                'unverified' => count(array_filter($citations, fn (array $row): bool => $row['comparison']['status'] === 'unchecked')),
            ],
        ];
    }

    public function export(): string
    {
        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, ['Location', 'Provider', 'Listing URL', 'Canonical name', 'Canonical address', 'Canonical phone', 'Canonical website', 'Observed name', 'Observed address', 'Observed phone', 'Observed website', 'Checked at', 'Provenance', 'Comparison', 'Correction status', 'Correction owner', 'Notes'], ',', '"', '');
        foreach ($this->citations() as $row) {
            $profile = $this->website->business(false, $row['location_id']);
            $values = [$row['location_id'], $row['provider'], $row['listing_url'], $profile['name'] ?? '', $this->address($profile), $profile['phone'] ?? '', $this->canonicalWebsite($profile), $row['observed_name'], $row['observed_address'], $row['observed_phone'], $row['observed_website'], $row['checked_at'], $row['provenance'], $row['comparison']['status'], $row['correction_status'], $row['correction_owner'], $row['notes']];
            fputcsv($stream, array_map($this->csvCell(...), $values), ',', '"', '');
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return $csv;
    }

    public function exportNap(): string
    {
        $settings = $this->website->get();
        $public = $this->website->publicData();
        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, ['Location', 'Business name', 'Public address', 'Address visibility', 'Public phone', 'Public email', 'Website', 'Operating hours', 'Special hours', 'Service areas', 'Profile links', 'Published revision'], ',', '"', '');
        foreach ([$public['profile'] + ['id' => 'primary'], ...$public['locations']] as $profile) {
            $hours = array_map(fn (array $row): string => $row['day'].': '.($row['closed'] ? 'Closed' : ($row['opens'].'–'.$row['closes'])), $profile['hours']);
            $specialHours = array_map(fn (array $row): string => $row['date'].': '.($row['closed'] ? 'Closed' : ($row['opens'].'–'.$row['closes'])), $profile['special_hours']);
            $profiles = array_map(fn (array $row): string => $row['label'].': '.$row['url'], $profile['profile_urls']);
            fputcsv($stream, array_map($this->csvCell(...), [$profile['id'], $profile['name'], $this->address($profile), $profile['address_visibility'], $profile['phone'], $profile['email'], $this->canonicalWebsite($profile), implode('; ', $hours), implode('; ', $specialHours), implode('; ', $profile['service_areas']), implode('; ', $profiles), $settings['published_version'] ?? 'Unpublished']), ',', '"', '');
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return $csv;
    }

    private function comparison(array $row, array $settings, array $public): array
    {
        $profile = $row['location_id'] === 'primary' ? $public['profile'] : collect($public['locations'])->firstWhere('id', $row['location_id']);
        $checked = ! empty($row['checked_at']);
        $stale = $checked && (($row['canonical_revision'] ?? null) !== $settings['published_version'] || CarbonImmutable::parse($row['checked_at'])->lt(now()->subDays(90)));
        $canonical = ['name' => $profile['name'] ?? '', 'address' => $this->address($profile ?? []), 'phone' => $profile['phone'] ?? '', 'website' => $profile ? $this->canonicalWebsite($profile) : ''];
        $fields = [];
        foreach ($canonical as $key => $value) {
            $observed = $row['observed_'.$key] ?? '';
            if ($key === 'address' && ($profile['address_visibility'] ?? 'hidden') === 'hidden') {
                $fields[$key] = $observed ? 'needs_review' : 'not_applicable';
            } elseif (! $checked || ! $observed || ! $value || ! $settings['published_version']) {
                $fields[$key] = 'unchecked';
            } else {
                $fields[$key] = $this->normalize($key, $observed) === $this->normalize($key, $value) ? 'matches' : 'difference';
            }
        }
        $status = ! $profile ? 'needs_review' : ($stale ? 'stale' : (in_array('difference', $fields, true) ? 'difference' : (in_array('needs_review', $fields, true) ? 'needs_review' : (in_array('unchecked', $fields, true) ? 'unchecked' : 'matches'))));
        $row['stale'] = $stale;
        $row['comparison'] = ['status' => $status, 'fields' => $fields, 'differing_fields' => array_keys(array_filter($fields, fn (string $state): bool => in_array($state, ['difference', 'needs_review'], true)))];

        return $row;
    }

    private function address(array $profile): string
    {
        if (($profile['address_visibility'] ?? 'hidden') !== 'visible') {
            return '';
        }

        return implode(', ', array_filter(array_map(fn (string $field): string => $profile[$field] ?? '', ['address', 'city', 'region', 'postal_code', 'country'])));
    }

    private function canonicalWebsite(array $profile): string
    {
        if (! empty($profile['page_id'])) {
            $page = $this->records->find('pages', $profile['page_id']);
            if (($page['status'] ?? '') === 'published' && ! empty($page['published'])) {
                return app(SeoBuilder::class)->url($page['published']);
            }
        }

        return rtrim(config('app.url'), '/').'/';
    }

    private function normalize(string $field, string $value): string
    {
        if ($field === 'phone') {
            $digits = preg_replace('/\D/', '', $value);

            return strlen($digits) === 11 && str_starts_with($digits, '1') ? substr($digits, 1) : $digits;
        }
        if ($field === 'website') {
            $url = parse_url($value);

            return preg_replace('/^www\./', '', strtolower($url['host'] ?? '')).(isset($url['port']) ? ':'.$url['port'] : '').rtrim($url['path'] ?? '', '/').(isset($url['query']) ? '?'.$url['query'] : '');
        }
        $text = mb_strtolower(trim(preg_replace('/[^\pL\pN]+/u', ' ', $value)));
        if ($field === 'address') {
            $replace = ['street' => 'st', 'road' => 'rd', 'avenue' => 'ave', 'boulevard' => 'blvd', 'drive' => 'dr', 'lane' => 'ln', 'court' => 'ct', 'suite' => 'ste', 'north' => 'n', 'south' => 's', 'east' => 'e', 'west' => 'w', 'united states' => 'us', 'united states of america' => 'us', 'canada' => 'ca'];
            foreach ($replace as $long => $short) {
                $text = preg_replace('/\b'.preg_quote($long, '/').'\b/u', $short, $text);
            }
        }

        return preg_replace('/\s+/u', ' ', $text);
    }

    private function csvCell(mixed $value): string
    {
        $text = (string) ($value ?? '');

        return preg_match('/^[\s\x{FEFF}]*[=+\-@]/u', $text) || preg_match('/^[\t\r\n]/', $text) ? "'".$text : $text;
    }

    private function contrast(string $first, string $second): float
    {
        $luminance = function (string $hex): float {
            $channels = array_map(function (string $channel): float {
                $value = hexdec($channel) / 255;

                return $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
            }, str_split(ltrim($hex, '#'), 2));

            return $channels[0] * 0.2126 + $channels[1] * 0.7152 + $channels[2] * 0.0722;
        };
        $a = $luminance($first);
        $b = $luminance($second);

        return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
    }
}

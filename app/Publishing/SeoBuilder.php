<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace App\Publishing;

final class SeoBuilder
{
    public function url(array $page): string
    {
        return rtrim(config('app.url'), '/').(($page['type'] ?? '') === 'home' ? '/' : '/p/'.$page['slug']);
    }

    public function build(array $page, array $business, array $publishedPages = [], bool $preview = false): array
    {
        $seo = $page['seo'] ?? [];
        $defaults = $business['seo'] ?? [];
        $typeDefaults = $defaults['page_types'][$page['type']] ?? [];
        $name = $business['name'] ?? $business['legal_name'] ?? '';
        $title = $seo['title'] ?? null;
        $title = $title ?: $page['title'].($name && $page['title'] !== $name ? ' · '.$name : '');
        $description = ($seo['description'] ?? '') ?: (($page['excerpt'] ?? '') ?: ($typeDefaults['description'] ?? $defaults['description'] ?? ''));
        $ownUrl = $this->url($page);
        $canonical = ($seo['canonical'] ?? '') ?: $ownUrl;
        $robots = $preview ? 'noindex,nofollow' : ($seo['robots'] ?? 'index,follow');
        $locale = $page['locale'] ?? 'en-US';
        $image = ($seo['og_image'] ?? '') ?: ($typeDefaults['og_image'] ?? $defaults['og_image'] ?? '');
        if ($image && str_starts_with($image, '/')) {
            $image = rtrim(config('app.url'), '/').$image;
        }
        $alternates = [];
        if (! empty($page['translation_group'])) {
            foreach ($publishedPages as $alternate) {
                if (($alternate['translation_group'] ?? '') === $page['translation_group'] && $this->isIndexable($alternate)) {
                    $alternates[$alternate['locale']] = $this->url($alternate);
                }
            }
        }
        $graph = ($seo['schema_mode'] ?? 'auto') === 'off' || $preview ? [] : $this->graph($page, $business, $canonical, $publishedPages);

        return [
            'title' => $title, 'description' => $description, 'canonical' => $canonical, 'robots' => $robots,
            'locale' => $locale, 'alternates' => $alternates, 'og_locale' => str_replace('-', '_', $locale),
            'og_title' => ($seo['og_title'] ?? '') ?: $title, 'og_description' => ($seo['og_description'] ?? '') ?: $description,
            'og_image' => $image, 'og_image_alt' => $seo['og_image_alt'] ?? $defaults['og_image_alt'] ?? $page['title'],
            'twitter_card' => $seo['twitter_card'] ?? ($image ? 'summary_large_image' : 'summary'),
            'og_type' => in_array($page['type'], ['article', 'case-study'], true) ? 'article' : 'website',
            'published_time' => $page['published_at'] ?? null, 'modified_time' => $page['updated_at'] ?? null,
            'schema' => $graph ? json_encode(['@context' => 'https://schema.org', '@graph' => $graph], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) : null,
            'verification' => $defaults['google_verification'] ?? null,
        ];
    }

    public function isIndexable(array $page): bool
    {
        return ! str_contains($page['seo']['robots'] ?? '', 'noindex') && (empty($page['seo']['canonical']) || $page['seo']['canonical'] === $this->url($page));
    }

    private function graph(array $page, array $business, string $canonical, array $publishedPages): array
    {
        $root = rtrim(config('app.url'), '/');
        $primaryBusinessId = $root.'/#business';
        $locationId = $business['location_id'] ?? null;
        $isBranch = is_string($locationId) && $locationId !== 'primary' && preg_match('/^[a-zA-Z0-9_-]{1,80}$/D', $locationId);
        $businessId = $isBranch ? $root.'/#location-'.rawurlencode($locationId) : $primaryBusinessId;
        $siteId = $root.'/#website';
        $pageId = $canonical.'#page';
        $name = $business['name'] ?? $business['legal_name'] ?? null;
        $primary = $isBranch ? ($business['primary_business'] ?? []) : $business;
        $primaryName = $primary['name'] ?? $primary['legal_name'] ?? null;
        $publisherId = $primaryName ? $primaryBusinessId : ($name ? $businessId : null);
        $graph = [];
        if ($isBranch && $primaryName) {
            $primary['logo_url'] ??= $business['logo_url'] ?? null;
            $graph[] = $this->businessEntity($primary, $primaryBusinessId, $root.'/');
        }
        if ($name) {
            $businessUrl = $root.'/';
            if ($isBranch) {
                if ($page['type'] === 'branch') {
                    $businessUrl = $this->url($page);
                } else {
                    foreach ($publishedPages as $candidate) {
                        if (($candidate['id'] ?? null) === ($business['page_id'] ?? null) && ($candidate['type'] ?? '') === 'branch') {
                            $businessUrl = $this->url($candidate);
                            break;
                        }
                    }
                }
            }
            $entity = $this->businessEntity($business, $businessId, $businessUrl);
            if ($isBranch && $primaryName) {
                $entity['parentOrganization'] = ['@id' => $primaryBusinessId];
            }
            $graph[] = $entity;
        }
        $graph[] = array_filter(['@type' => 'WebSite', '@id' => $siteId, 'url' => $root.'/', 'name' => $primaryName ?: $name, 'publisher' => $publisherId ? ['@id' => $publisherId] : null]);
        $type = match ($page['type']) {
            'about' => 'AboutPage', 'contact' => 'ContactPage', 'collection', 'careers' => 'CollectionPage',
            default => 'WebPage',
        };
        if (in_array($page['seo']['schema_type'] ?? '', ['WebPage', 'AboutPage', 'ContactPage', 'CollectionPage', 'WebApplication'], true)) {
            $type = $page['seo']['schema_type'];
        }
        $node = ['@type' => $type, '@id' => $pageId, 'url' => $canonical, 'name' => $page['title'], 'inLanguage' => $page['locale'] ?? 'en-US', 'isPartOf' => ['@id' => $siteId]];
        if ($page['type'] === 'branch' && $isBranch && $name) {
            $node['mainEntity'] = ['@id' => $businessId];
        }
        if (! empty($page['excerpt'])) {
            $node['description'] = $page['excerpt'];
        }
        if (! empty($page['published_at'])) {
            $node['datePublished'] = $page['published_at'];
        }
        if (! empty($page['updated_at'])) {
            $node['dateModified'] = $page['updated_at'];
        }
        if (($page['seo']['schema_mode'] ?? 'auto') === 'custom' && is_array($page['seo']['schema_custom'] ?? null)) {
            $custom = $page['seo']['schema_custom'];
            unset($custom['@context'], $custom['@id'], $custom['@graph'], $custom['url'], $custom['isPartOf'], $custom['publisher']);
            $node = array_replace($node, $custom);
        }
        if (in_array($page['type'], ['service', 'service-area'], true)) {
            $serviceId = $canonical.'#service';
            $node['mainEntity'] = ['@id' => $serviceId];
            $graph[] = array_filter(['@type' => 'Service', '@id' => $serviceId, 'name' => $page['title'], 'description' => $page['excerpt'] ?? null, 'provider' => $name ? ['@id' => $businessId] : null, 'areaServed' => $page['service_area'] ?? null]);
        }
        if (in_array($page['type'], ['article', 'case-study'], true)) {
            $article = ['@type' => 'BlogPosting', '@id' => $canonical.'#article', 'headline' => $page['title'], 'mainEntityOfPage' => ['@id' => $pageId]];
            foreach (['datePublished', 'dateModified', 'description'] as $property) {
                if (isset($node[$property])) {
                    $article[$property] = $node[$property];
                }
            }
            if (! empty($page['author_name'])) {
                $article['author'] = ['@type' => 'Person', 'name' => $page['author_name']];
            }
            if ($publisherId) {
                $article['publisher'] = ['@id' => $publisherId];
            }
            $graph[] = $article;
        }
        if ($page['type'] === 'faq' && ! empty($page['faq'])) {
            $node['@type'] = 'FAQPage';
            $node['mainEntity'] = array_map(fn (array $item): array => ['@type' => 'Question', 'name' => $item['question'], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $item['answer']]], $page['faq']);
        }
        if ($page['type'] !== 'home') {
            $breadcrumbsId = $canonical.'#breadcrumbs';
            $node['breadcrumb'] = ['@id' => $breadcrumbsId];
            $graph[] = ['@type' => 'BreadcrumbList', '@id' => $breadcrumbsId, 'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => $root.'/'],
                ['@type' => 'ListItem', 'position' => 2, 'name' => $page['title'], 'item' => $canonical],
            ]];
        }
        $graph[] = $node;

        return $graph;
    }

    private function businessEntity(array $business, string $id, string $url): array
    {
        $entity = [
            '@type' => match (strtolower(trim($business['trade'] ?? ''))) {
                'plumber', 'plumbing' => 'Plumber',
                'electrician', 'electrical' => 'Electrician',
                'hvac', 'hvacbusiness' => 'HVACBusiness',
                'roofer', 'roofing', 'roofingcontractor' => 'RoofingContractor',
                'homeandconstructionbusiness' => 'HomeAndConstructionBusiness',
                default => 'GeneralContractor',
            },
            '@id' => $id, 'name' => $business['name'] ?? $business['legal_name'], 'url' => $url,
        ];
        foreach (['phone' => 'telephone', 'email' => 'email'] as $field => $property) {
            if (! empty($business[$field])) {
                $entity[$property] = $business[$field];
            }
        }
        if (! isset($business['address_visibility']) || $business['address_visibility'] === 'visible') {
            $address = array_filter([
                '@type' => 'PostalAddress', 'streetAddress' => $business['address'] ?? null, 'addressLocality' => $business['city'] ?? null,
                'addressRegion' => $business['region'] ?? null, 'postalCode' => $business['postal_code'] ?? null, 'addressCountry' => $business['country'] ?? null,
            ]);
            if (count($address) > 1) {
                $entity['address'] = $address;
            }
        }
        if (! empty($business['service_areas']) && is_array($business['service_areas'])) {
            $areas = array_values(array_filter($business['service_areas'], fn (mixed $area): bool => is_string($area) && trim($area) !== ''));
            if ($areas) {
                $entity['areaServed'] = $areas;
            }
        }
        $sameAs = [];
        foreach ($business['profile_urls'] ?? [] as $profile) {
            $value = is_array($profile) ? ($profile['url'] ?? '') : '';
            $parts = is_string($value) ? parse_url($value) : false;
            if (is_string($value) && filter_var($value, FILTER_VALIDATE_URL) && in_array($parts['scheme'] ?? '', ['https', 'http'], true) && ! isset($parts['user']) && ! isset($parts['pass']) && ! preg_match('/[\x00-\x20\x7f\\\\]/', $value)) {
                $sameAs[] = $value;
            }
        }
        if ($sameAs) {
            $entity['sameAs'] = array_values(array_unique($sameAs));
        }
        if (is_string($business['logo_url'] ?? null) && preg_match('~^/files/[a-zA-Z0-9_-]+$~D', $business['logo_url'])) {
            $entity['logo'] = rtrim(config('app.url'), '/').$business['logo_url'];
        }
        foreach (['hours' => 'openingHoursSpecification', 'special_hours' => 'specialOpeningHoursSpecification'] as $field => $property) {
            $hours = $this->hours($business[$field] ?? [], $field === 'special_hours');
            if ($hours) {
                $entity[$property] = $hours;
            }
        }

        return $entity;
    }

    private function hours(array $rules, bool $special): array
    {
        $specifications = [];
        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                continue;
            }
            $specification = ['@type' => 'OpeningHoursSpecification'];
            if ($special) {
                $date = $rule['date'] ?? '';
                if (! is_string($date) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $matches) || ! checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])) {
                    continue;
                }
                $specification['validFrom'] = $date;
                $specification['validThrough'] = $date;
            } else {
                if (! in_array($rule['day'] ?? '', WebsiteSettings::DAYS, true)) {
                    continue;
                }
                $specification['dayOfWeek'] = 'https://schema.org/'.$rule['day'];
            }
            if ($rule['closed'] ?? false) {
                $specification['opens'] = '00:00';
                $specification['closes'] = '00:00';
            } else {
                if (! preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $rule['opens'] ?? '') || ! preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $rule['closes'] ?? '')) {
                    continue;
                }
                $specification['opens'] = $rule['opens'];
                $specification['closes'] = $rule['closes'];
            }
            $specifications[] = $specification;
        }

        return $specifications;
    }
}

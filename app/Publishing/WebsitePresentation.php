<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace App\Publishing;

final class WebsitePresentation
{
    public function __construct(private readonly FontLibrary $fonts) {}

    public function links(array $links, array $pages, array $business): array
    {
        $result = [];
        foreach ($links as $link) {
            $href = $this->href($link, $pages, $business);
            if ($href !== '') {
                $result[] = ['label' => $link['label'] ?? '', 'href' => $href];
            }
        }

        return $result;
    }

    public function href(array $link, array $pages, array $business): string
    {
        if (($link['kind'] ?? '') === 'page') {
            foreach ($pages as $page) {
                if (($page['id'] ?? '') === ($link['page_id'] ?? null)) {
                    return $page['type'] === 'home' ? '/' : '/p/'.$page['slug'];
                }
            }

            return '';
        }
        if (($link['kind'] ?? '') === 'phone') {
            $phone = preg_replace('/[^+0-9]/', '', $business['phone'] ?? '');

            return $phone !== '' ? 'tel:'.$phone : '';
        }
        if (($link['kind'] ?? '') === 'anchor') {
            $anchor = $link['anchor'] ?? '';
            if (! preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,79}$/D', $anchor)) {
                return '';
            }

            return in_array($anchor, ['contact', 'request-service'], true) ? '/#request-service' : '/#home-'.$anchor;
        }
        $url = $link['url'] ?? '';
        if (! is_string($url) || preg_match('/[\x00-\x20<>"\\\\]/', $url)) {
            return '';
        }
        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return $url;
        }

        return filter_var($url, FILTER_VALIDATE_URL) && in_array(strtolower(parse_url($url, PHP_URL_SCHEME) ?? ''), ['https', 'http'], true) ? $url : '';
    }

    public function css(array $appearance): string
    {
        $defaults = ['primary' => '#27483d', 'accent' => '#dce89d', 'background' => '#f7f6f0', 'surface' => '#ffffff', 'text' => '#243b32', 'muted' => '#65746a', 'border' => '#d8dfd5'];
        $tokens = [];
        foreach ($defaults as $name => $default) {
            $value = $appearance['colors'][$name] ?? $default;
            $tokens[$name] = is_string($value) && preg_match('/^#[0-9a-f]{6}$/iD', $value) ? $value : $default;
        }
        $radius = ['square' => '0', 'soft' => '6px', 'rounded' => '24px'][$appearance['button_radius'] ?? 'soft'] ?? '6px';
        $width = ($appearance['content_width'] ?? '') === 'wide' ? '1440px' : '1200px';
        $spacing = ['compact' => '40px', 'standard' => '64px', 'generous' => '88px'][$appearance['section_spacing'] ?? 'standard'] ?? '64px';
        $scale = ['compact' => '.9', 'standard' => '1', 'large' => '1.12'][$appearance['type_scale'] ?? 'standard'] ?? '1';
        $css = ':root{';
        foreach ($tokens as $key => $value) {
            $css .= '--website-'.$key.':'.$value.';';
        }
        $css .= '--ink:var(--website-text);--forest:var(--website-primary);--muted:var(--website-muted);--paper:var(--website-background);--line:var(--website-border);--lime:var(--website-accent);';
        $css .= '--website-heading-font:'.$this->fonts->family($appearance['heading_font_id'] ?? 'manrope').';--website-body-font:'.$this->fonts->family($appearance['body_font_id'] ?? 'inter').';';
        $css .= '--website-radius:'.$radius.';--website-width:'.$width.';--website-spacing:'.$spacing.';--website-scale:'.$scale.';}';

        return $css;
    }
}

<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace App\Publishing;

use Closure;
use Illuminate\Validation\ValidationException;

final class BlockNoteRenderer
{
    private int $blockCount = 0;

    public function __construct(private readonly ?Closure $resolveImage = null) {}

    /** @param array<int, array<string, mixed>> $blocks */
    public function render(array $blocks): string
    {
        $this->blockCount = 0;
        if (! array_is_list($blocks) || strlen(json_encode($blocks, JSON_THROW_ON_ERROR)) > 1_000_000) {
            $this->fail('Content must be a block list smaller than 1 MB.');
        }

        $html = $this->blocks($blocks, 0);
        if (strlen($html) > 2_000_000) {
            $this->fail('Rendered content exceeds 2 MB.');
        }

        return $html;
    }

    private function blocks(array $blocks, int $depth): string
    {
        if ($depth > 6) {
            $this->fail('Content nesting exceeds six levels.');
        }
        $html = '';
        $list = null;
        foreach ($blocks as $block) {
            if (! is_array($block) || ++$this->blockCount > 1000) {
                $this->fail('Content contains invalid blocks or exceeds 1,000 blocks.');
            }
            $type = $block['type'] ?? 'paragraph';
            $props = $block['props'] ?? [];
            if (! is_array($props)) {
                $this->fail('Block properties must be an object.');
            }
            $nextList = match ($type) {
                'bulletListItem', 'checkListItem' => 'ul',
                'numberedListItem' => 'ol',
                default => null,
            };
            if ($list !== $nextList) {
                $html .= $list ? "</{$list}>" : '';
                $html .= $nextList ? "<{$nextList}>" : '';
                $list = $nextList;
            }
            $children = $block['children'] ?? [];
            if (! is_array($children) || ! array_is_list($children)) {
                $this->fail('Block children must be a list.');
            }
            $nested = $children ? $this->blocks($children, $depth + 1) : '';
            $alignment = $props['textAlignment'] ?? 'left';
            if (! in_array($alignment, ['left', 'center', 'right', 'justify'], true)) {
                $this->fail('Unsupported text alignment.');
            }
            $attributes = $alignment !== 'left' ? ' class="text-'.$alignment.'"' : '';
            if ($type === 'table') {
                $html .= $this->table($block['content'] ?? []).$nested;

                continue;
            }
            if ($type === 'image') {
                $html .= $this->image($props).$nested;

                continue;
            }
            $inline = $this->inline($block['content'] ?? []);
            $html .= match ($type) {
                'paragraph' => "<p{$attributes}>{$inline}</p>".$nested,
                'heading' => $this->heading($props, $inline, $attributes).$nested,
                'quote' => "<blockquote{$attributes}>{$inline}{$nested}</blockquote>",
                'bulletListItem', 'numberedListItem' => "<li{$attributes}>{$inline}{$nested}</li>",
                'checkListItem' => '<li class="checklist">'.(($props['checked'] ?? false) === true ? '☑ ' : '☐ ').$inline.$nested.'</li>',
                default => $this->fail('Unsupported block type: '.(is_string($type) ? $type : 'invalid')),
            };
        }

        return $html.($list ? "</{$list}>" : '');
    }

    private function heading(array $props, string $inline, string $attributes): string
    {
        $level = $props['level'] ?? 2;
        if (! is_int($level) || $level < 1 || $level > 6) {
            $this->fail('Heading level must be between 1 and 6.');
        }

        return "<h{$level}{$attributes}>{$inline}</h{$level}>";
    }

    private function inline(mixed $items, bool $insideLink = false): string
    {
        if (is_string($items)) {
            return nl2br($this->escape($items));
        }
        if (! is_array($items) || ! array_is_list($items) || count($items) > 1000) {
            $this->fail('Invalid inline content.');
        }
        $html = '';
        foreach ($items as $item) {
            if (! is_array($item)) {
                $this->fail('Invalid text element.');
            }
            if (($item['type'] ?? '') === 'link') {
                if ($insideLink) {
                    $this->fail('Nested links are unsupported.');
                }
                $url = $this->safeUrl($item['href'] ?? '');
                $html .= '<a href="'.$this->escape($url).'" rel="noopener noreferrer">'.$this->inline($item['content'] ?? [], true).'</a>';

                continue;
            }
            if (($item['type'] ?? '') !== 'text' || ! is_string($item['text'] ?? null)) {
                $this->fail('Only text and links are supported inline.');
            }
            $text = nl2br($this->escape($item['text']));
            $styles = $item['styles'] ?? [];
            if (! is_array($styles)) {
                $this->fail('Text styles must be an object.');
            }
            foreach ($styles as $style => $value) {
                $tag = match ($style) {
                    'bold' => 'strong', 'italic' => 'em', 'underline' => 'u', 'strike' => 's', 'code' => 'code',
                    'textColor', 'backgroundColor' => null,
                    default => $this->fail('Unsupported text style.'),
                };
                if ($tag !== null && $value === true) {
                    $text = "<{$tag}>{$text}</{$tag}>";
                }
                if ($tag === null && $value !== 'default') {
                    if (! is_string($value) || ! in_array($value, ['gray', 'brown', 'red', 'orange', 'yellow', 'green', 'blue', 'purple', 'pink'], true)) {
                        $this->fail('Unsupported text color.');
                    }
                    $text = '<span class="'.($style === 'textColor' ? 'color-' : 'highlight-').$value.'">'.$text.'</span>';
                }
            }
            $html .= $text;
        }

        return $html;
    }

    private function table(mixed $content): string
    {
        if (! is_array($content) || ($content['type'] ?? '') !== 'tableContent' || ! is_array($content['rows'] ?? null) || count($content['rows']) > 100) {
            $this->fail('Tables require tableContent with at most 100 rows.');
        }
        $html = '<div class="table-scroll"><table><tbody>';
        foreach ($content['rows'] as $row) {
            if (! is_array($row) || ! is_array($row['cells'] ?? null) || count($row['cells']) > 20) {
                $this->fail('Tables support at most 20 cells per row.');
            }
            $html .= '<tr>';
            foreach ($row['cells'] as $cell) {
                $html .= '<td>'.$this->inline(is_array($cell) && ($cell['type'] ?? '') === 'tableCell' ? ($cell['content'] ?? []) : $cell).'</td>';
            }
            $html .= '</tr>';
        }

        return $html.'</tbody></table></div>';
    }

    private function image(array $props): string
    {
        $mediaId = $props['mediaId'] ?? null;
        if (! $mediaId && is_string($props['url'] ?? null) && preg_match('~^/files/([a-zA-Z0-9_-]+)$~', $props['url'], $matches)) {
            $mediaId = $matches[1];
        }
        if (! is_string($mediaId) || ! $this->resolveImage) {
            $this->fail('Images must reference an approved local media file.');
        }
        $url = ($this->resolveImage)($mediaId);
        if (! is_string($url) || ! str_starts_with($url, '/files/')) {
            $this->fail('The image is unavailable or is not public.');
        }
        $alt = $props['alt'] ?? $props['caption'] ?? $props['name'] ?? '';
        $caption = $props['caption'] ?? '';
        if (! is_string($alt) || ! is_string($caption)) {
            $this->fail('Image alt text and caption must be text.');
        }

        return '<figure><img src="'.$this->escape($url).'" alt="'.$this->escape($alt).'" loading="lazy" decoding="async">'.($caption ? '<figcaption>'.$this->escape($caption).'</figcaption>' : '').'</figure>';
    }

    public function safeUrl(mixed $url): string
    {
        if (! is_string($url) || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            $this->fail('Invalid link URL.');
        }
        if (preg_match('~^/(?!/)~', $url) || preg_match('~^#[a-zA-Z0-9_-]+$~', $url)) {
            return $url;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (! in_array($scheme, ['https', 'http', 'mailto', 'tel'], true) || (in_array($scheme, ['http', 'https'], true) && ! filter_var($url, FILTER_VALIDATE_URL))) {
            $this->fail('Links must use http, https, mailto, tel, or a local path.');
        }

        return $url;
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['content' => $message]);
    }
}

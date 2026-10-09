<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace App\Publishing;

use Illuminate\Validation\ValidationException;

final class LayoutRenderer
{
    private int $count = 0;

    private bool $hasContact = false;

    public function render(array $project, string $content = '', string $contactWidget = ''): array
    {
        $this->count = 0;
        $this->hasContact = false;
        if (strlen(json_encode($project, JSON_THROW_ON_ERROR)) > 1_000_000) {
            $this->fail('The layout exceeds 1 MB.');
        }
        if (! $project) {
            return ['body' => $content, 'css' => ''];
        }
        $component = $project['pages'][0]['frames'][0]['component'] ?? $project['components'] ?? null;
        if ($component === null) {
            $this->fail('The layout must contain a GrapesJS page frame or components.');
        }
        $hasContent = false;
        $body = $this->node($component, $content, $contactWidget, 0, $hasContent);
        if (! $hasContent) {
            $body .= $content;
        }
        if (strlen($body) > 2_000_000) {
            $this->fail('Rendered layout exceeds 2 MB.');
        }
        $css = '';
        foreach ($project['styles'] ?? [] as $rule) {
            if (! is_array($rule) || ! empty($rule['atRuleType']) && $rule['atRuleType'] !== 'media') {
                $this->fail('Only regular and responsive CSS rules are supported.');
            }
            $selectors = $rule['selectors'] ?? [];
            if (is_string($selectors)) {
                $selectors = [$selectors];
            }
            $selector = implode(',', array_map(function (mixed $item): string {
                if (is_array($item)) {
                    $item = (($item['type'] ?? 1) === 2 ? '#' : '.').($item['name'] ?? '');
                }
                if (! is_string($item) || ! preg_match('/^[a-zA-Z0-9.#_ :>+~,\[\]="-]+$/D', $item) || strlen($item) > 300) {
                    $this->fail('Invalid CSS selector.');
                }

                return $item;
            }, $selectors));
            if (! $selector) {
                continue;
            }
            $declaration = $selector.'{'.$this->style($rule['style'] ?? []).'}';
            if (($rule['atRuleType'] ?? '') === 'media') {
                $media = $rule['mediaText'] ?? '';
                if (! preg_match('/^\((?:max|min)-width:\s*\d{2,4}px\)$/D', $media)) {
                    $this->fail('Responsive rules must specify a min/max pixel width.');
                }
                $declaration = '@media '.$media.'{'.$declaration.'}';
            }
            $css .= $declaration;
        }

        return ['body' => $body, 'css' => $css];
    }

    private function node(mixed $node, string $content, string $contactWidget, int $depth, bool &$hasContent): string
    {
        if (++$this->count > 2000 || $depth > 15) {
            $this->fail('Layout nesting or element limit exceeded.');
        }
        if (is_string($node)) {
            return htmlspecialchars($node, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        if (! is_array($node)) {
            $this->fail('Invalid layout element.');
        }
        if (array_is_list($node)) {
            $html = '';
            foreach ($node as $child) {
                $html .= $this->node($child, $content, $contactWidget, $depth + 1, $hasContent);
            }

            return $html;
        }
        if (! empty($node['script']) || ! empty($node['script-export'])) {
            $this->fail('Executable components are prohibited.');
        }
        $type = $node['type'] ?? 'default';
        if ($type === 'cms-content') {
            if ($hasContent) {
                $this->fail('A layout may contain only one writing content block.');
            }
            $hasContent = true;

            return $content;
        }
        if ($type === 'cms-contact') {
            if ($this->hasContact) {
                $this->fail('A layout may contain only one contact form.');
            }
            $this->hasContact = true;

            return $contactWidget;
        }
        if (! in_array($type, ['default', 'wrapper', 'text', 'textnode', 'link', 'image', 'section', ''], true)) {
            $this->fail('Unsupported layout component: '.$type);
        }
        $tag = strtolower($node['tagName'] ?? ($type === 'link' ? 'a' : ($type === 'image' ? 'img' : 'div')));
        if ($type === 'wrapper' && $tag === 'body') {
            $tag = 'div';
        }
        if (! in_array($tag, ['div', 'section', 'header', 'footer', 'main', 'aside', 'article', 'nav', 'span', 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'a', 'strong', 'em', 'blockquote', 'br', 'hr', 'img'], true)) {
            $this->fail('Unsupported layout HTML tag.');
        }
        $attributes = '';
        foreach ($node['attributes'] ?? [] as $key => $value) {
            if (! in_array($key, ['id', 'class', 'title', 'href', 'alt', 'src', 'aria-label', 'role'], true) || ! is_scalar($value)) {
                $this->fail('Unsupported layout attribute.');
            }
            $value = (string) $value;
            if ($key === 'href') {
                (new BlockNoteRenderer)->safeUrl($value);
            }
            if ($key === 'src' && ! preg_match('~^/files/[a-zA-Z0-9_-]+$~D', $value)) {
                $this->fail('Layout images must use local media files.');
            }
            $attributes .= ' '.$key.'="'.htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'"';
        }
        if (! empty($node['classes'])) {
            $classes = array_map(fn (mixed $class): string => is_string($class) ? $class : ($class['name'] ?? ''), $node['classes']);
            $attributes .= ' class="'.htmlspecialchars(implode(' ', $classes), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'"';
        }
        if (! empty($node['style'])) {
            $attributes .= ' style="'.htmlspecialchars($this->style($node['style']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'"';
        }
        if (in_array($tag, ['img', 'br', 'hr'], true)) {
            return '<'.$tag.$attributes.'>';
        }
        $text = $node['content'] ?? '';
        if (! is_string($text)) {
            $this->fail('Layout text must be a string.');
        }
        $html = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        if ($type === 'textnode') {
            return $html;
        }
        $html .= $this->node($node['components'] ?? [], $content, $contactWidget, $depth + 1, $hasContent);

        return '<'.$tag.$attributes.'>'.$html.'</'.$tag.'>';
    }

    private function style(mixed $style): string
    {
        if (! is_array($style) || count($style) > 100) {
            $this->fail('Invalid layout styles.');
        }
        $css = '';
        foreach ($style as $property => $value) {
            if (! preg_match('/^[a-z-]{2,40}$/D', $property) || ! is_scalar($value) || strlen((string) $value) > 200 || preg_match('/[<>;{}\\\\@]|url\s*\(|expression|behavior|binding/i', (string) $value.$property)) {
                $this->fail('Unsafe CSS style.');
            }
            $css .= $property.':'.$value.';';
        }

        return $css;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['layout' => $message]);
    }
}

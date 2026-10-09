<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace Tests\Unit;

use App\Publishing\BlockNoteRenderer;
use App\Publishing\LayoutRenderer;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BlockNoteRendererTest extends TestCase
{
    public function test_supported_blocks_render_with_escaping_and_grouped_lists(): void
    {
        $html = (new BlockNoteRenderer)->render([
            ['type' => 'heading', 'props' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'Roof & repair', 'styles' => []]]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => '<script>alert(1)</script>', 'styles' => ['bold' => true]]]],
            ['type' => 'bulletListItem', 'content' => 'One'],
            ['type' => 'bulletListItem', 'content' => 'Two'],
            ['type' => 'numberedListItem', 'content' => [['type' => 'link', 'href' => '/p/help', 'content' => [['type' => 'text', 'text' => 'Help', 'styles' => []]]]]],
            ['type' => 'checkListItem', 'props' => ['checked' => true], 'content' => 'Done'],
            ['type' => 'quote', 'content' => 'A clear next step'],
            ['type' => 'table', 'content' => ['type' => 'tableContent', 'rows' => [['cells' => [[['type' => 'text', 'text' => 'Scope', 'styles' => []]], ['type' => 'tableCell', 'content' => [['type' => 'text', 'text' => 'Repair', 'styles' => []]]]]]]]],
        ]);
        $this->assertStringContainsString('<h2>Roof &amp; repair</h2>', $html);
        $this->assertStringContainsString('<strong>&lt;script&gt;alert(1)&lt;/script&gt;</strong>', $html);
        $this->assertStringContainsString('<ul><li>One</li><li>Two</li></ul><ol>', $html);
        $this->assertStringContainsString('<td>Repair</td>', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    #[DataProvider('unsafeContent')]
    public function test_unsafe_or_unsupported_content_is_rejected(array $blocks): void
    {
        $this->expectException(ValidationException::class);
        (new BlockNoteRenderer)->render($blocks);
    }

    public static function unsafeContent(): array
    {
        return [
            'html block' => [[['type' => 'html', 'content' => '<script>alert(1)</script>']]],
            'javascript link' => [[['type' => 'paragraph', 'content' => [['type' => 'link', 'href' => 'javascript:alert(1)', 'content' => []]]]]],
            'protocol relative' => [[['type' => 'paragraph', 'content' => [['type' => 'link', 'href' => '//evil.test', 'content' => []]]]]],
            'data link' => [[['type' => 'paragraph', 'content' => [['type' => 'link', 'href' => 'data:text/html,x', 'content' => []]]]]],
            'external image' => [[['type' => 'image', 'props' => ['url' => 'https://external.test/image.png']]]],
            'inline mention' => [[['type' => 'paragraph', 'content' => [['type' => 'mention', 'text' => 'name']]]]],
            'style injection' => [[['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'name', 'styles' => ['textColor' => 'red;background:url(javascript:x)']]]]]],
            'invalid heading' => [[['type' => 'heading', 'props' => ['level' => 99], 'content' => 'Bad']]],
            'too many blocks' => [array_fill(0, 1001, ['type' => 'paragraph', 'content' => ''])],
        ];
    }

    public function test_image_requires_resolved_public_local_file(): void
    {
        $renderer = new BlockNoteRenderer(fn (string $id): ?string => $id === 'public' ? '/files/public' : null);
        $html = $renderer->render([['type' => 'image', 'props' => ['url' => '/files/public', 'caption' => 'A & B']]]);
        $this->assertStringContainsString('src="/files/public"', $html);
        $this->assertStringContainsString('alt="A &amp; B"', $html);
        $this->expectException(ValidationException::class);
        $renderer->render([['type' => 'image', 'props' => ['mediaId' => 'private']]]);
    }

    public function test_grapesjs_layout_renders_registered_content_and_safe_styles(): void
    {
        $project = ['pages' => [['frames' => [['component' => ['type' => 'wrapper', 'components' => [
            ['type' => 'text', 'tagName' => 'h2', 'content' => 'Hello & welcome', 'style' => ['color' => '#123456']],
            ['type' => 'cms-content'],
        ]]]]]]];
        $result = (new LayoutRenderer)->render($project, '<p>Approved content</p>');
        $this->assertStringContainsString('Hello &amp; welcome', $result['body']);
        $this->assertStringContainsString('<p>Approved content</p>', $result['body']);
        $this->assertStringContainsString('color:#123456;', $result['body']);
    }

    public function test_grapesjs_script_and_css_injection_are_rejected(): void
    {
        $this->expectException(ValidationException::class);
        (new LayoutRenderer)->render(['components' => [['type' => 'text', 'script' => 'alert(1)', 'content' => 'Hello']]]);
    }

    public function test_layout_cannot_multiply_large_content_with_repeated_slots(): void
    {
        $this->expectException(ValidationException::class);
        (new LayoutRenderer)->render(['components' => [['type' => 'cms-content'], ['type' => 'cms-content']]], '<p>Content</p>');
    }
}

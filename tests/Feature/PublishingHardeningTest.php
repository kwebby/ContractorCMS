<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace Tests\Feature;

use App\Contracts\RecordRepository;
use App\Identity\CmsUser;
use App\Publishing\PublishingService;
use App\Publishing\ThemeManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class PublishingHardeningTest extends TestCase
{
    use RefreshDatabase;

    private array $temporaryPaths = [];

    private array $themePaths = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['cms.data_provider' => 'postgres', 'cms.mfa_required' => false, 'app.url' => 'http://localhost']);
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryPaths as $path) {
            @unlink($path);
        }
        foreach ($this->themePaths as $path) {
            File::deleteDirectory($path);
        }
        parent::tearDown();
    }

    public function test_dynamic_asset_routes_fall_back_to_the_front_controller_in_the_nginx_example(): void
    {
        foreach (['/assets/public-site.css', '/assets/public-site.js', '/assets/home-sections.css'] as $path) {
            $this->assertFileDoesNotExist(public_path(ltrim($path, '/')));
            $this->get($path)->assertOk();
        }
        $configuration = File::get(base_path('deploy/nginx/contractor-cms.conf'));
        $this->assertSame(1, preg_match('#location \^~ /assets/ \{([^}]*)\}#', $configuration, $block));
        $this->assertStringContainsString('try_files $uri /index.php?$query_string;', $block[1]);
    }

    public function test_robots_txt_is_served_by_the_application_rather_than_a_static_file(): void
    {
        $this->assertFileDoesNotExist(public_path('robots.txt'));
        $this->get('/robots.txt')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertSee('Disallow: /api/', false)
            ->assertSee('Disallow: /app/', false)
            ->assertSee('Sitemap: http://localhost/sitemap.xml', false);
    }

    #[DataProvider('externalStylesheetReferences')]
    public function test_stylesheet_references_outside_the_package_are_rejected(string $css): void
    {
        $this->expectException(ValidationException::class);
        $this->upload($this->package(['assets/style.css' => $css]));
    }

    public static function externalStylesheetReferences(): array
    {
        return [
            'quoted URL with whitespace' => ['a{background:url("https://evil.example/x y")}'],
            'single-quoted URL with whitespace' => ["a{background:url( 'https://evil.example/x y' )}"],
            'uppercase URL function' => ['a{background:URL(https://evil.example/x.png)}'],
            'image-set string' => ['a{background-image:image-set("https://evil.example/x.png" 1x)}'],
            'prefixed image-set string' => ['a{background-image:-webkit-image-set("https://evil.example/x.png" 1x)}'],
            'src function string' => ['a{cursor:src("https://evil.example/x")}'],
            'image function string' => ['a{background-image:image("https://evil.example/x.png")}'],
            'cross-fade string' => ['a{background-image:cross-fade("https://evil.example/x.png" 50%, url(images/a.png))}'],
            'import string' => ['@import "https://evil.example/style.css";'],
            'import URL' => ['@import url(https://evil.example/style.css);'],
            'protocol-relative URL' => ['a{background:url(//evil.example/x.png)}'],
            'root-relative URL' => ['a{background:url("/files/private-photo")}'],
            'data URL' => ['a{background:url("data:image/png;base64,AAAA")}'],
            'custom property string' => [':root{--hero:"https://evil.example/x.png"}a{background-image:image-set(var(--hero) 1x)}'],
            'custom property whitespace' => [":root{--hero:\" \thttps://evil.example/x.png\"}a{background-image:image-set(var(--hero) 1x)}"],
            'variable fallback string' => ['a{background-image:image-set(var(--missing, "//evil.example/x.png") 1x)}'],
            'comment marker inside strings' => ['a{content:"/*"}b{background:url("https://evil.example/x y")}c{content:"*/"}'],
            'comment marker inside unquoted URL' => ['a{background:url(images/a.png/*)}b{background:url(https://evil.example/x.png)}/* */'],
            'unterminated string' => ["a{content:\"open\nb{background:url(https://evil.example/x.png)}"],
        ];
    }

    public function test_packaged_stylesheet_references_and_text_strings_remain_valid(): void
    {
        $css = <<<'CSS'
/* Author: ramanpal singh | URL: https://kwebby.com */
@font-face { font-family: "Brand Sans"; src: url( "fonts/brand.woff2" ) format("woff2"); font-display: swap; }
.hero { background-image: url(images/hero.webp); }
.hero-sharp { background-image: image-set("images/hero.webp" 1x, url('images/hero-large.webp') 2x); }
.label::before { content: "Call: "; }
.breadcrumb li + li::before { content: " / "; }
a[href^="tel:"], a[href^="https://"]::after { content: "Source: us"; }
:root { --theme-label: "Loading..."; }
CSS;
        $theme = $this->upload($this->package(['assets/style.css' => $css]));
        $this->assertSame('validated', $theme['status']);
    }

    #[DataProvider('staticTemplateText')]
    public function test_static_template_text_may_contain_reserved_twig_words(string $text, string $expected): void
    {
        $theme = $this->upload($this->package(['templates/page.twig' => '<div class="theme">'.$text.'{# range, include and source are fine in comments #}{{ page.content }}</div>']));
        $this->assertSame('validated', $theme['status']);
        app(ThemeManager::class)->activate($theme['id']);
        $render = app(ThemeManager::class)->render(['title' => 'Services', 'type' => 'page'], ['name' => 'Trades'], '<p>Body</p>', '');
        $this->assertStringContainsString($expected, $render['body']);
    }

    public static function staticTemplateText(): array
    {
        return [
            'range' => ['<p>Our service range</p>', '<p>Our service range</p>'],
            'include' => ['<p>Quotes include permits</p>', '<p>Quotes include permits</p>'],
            'ellipsis' => ['<p>Loading...</p>', '<p>Loading...</p>'],
            'source' => ['<p>Source: us</p>', '<p>Source: us</p>'],
            'random and constant' => ['<p>No random fees, constant updates and a plan that matches your budget</p>', '<p>No random fees, constant updates and a plan that matches your budget</p>'],
            'reserved words in a string literal' => ['<p>{{ "Our service range"|upper }}</p>', '<p>OUR SERVICE RANGE</p>'],
        ];
    }

    #[DataProvider('reservedTwigExpressions')]
    public function test_reserved_twig_expressions_are_rejected(string $expression): void
    {
        try {
            $this->upload($this->package(['templates/page.twig' => '<div>'.$expression.'{{ page.content }}</div>']));
            $this->fail('A reserved Twig expression must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertSame('Template contains prohibited executable elements or expressions.', $exception->errors()['file'][0]);
        }
    }

    public static function reservedTwigExpressions(): array
    {
        return [
            'range operator' => ['{{ 1..3 }}'],
            'power operator' => ['{{ 2 ** 64 }}'],
            'regular expression operator' => ['{% if site.name matches "/^(a+)+$/" %}x{% endif %}'],
            'range function' => ['{{ range(1, 3) }}'],
            'random function' => ['{{ random() }}'],
            'source function' => ['{{ source("page") }}'],
            'include tag' => ['{% include "page" %}'],
            'constant function' => ['{{ constant("PHP_VERSION") }}'],
            'constant test' => ['{% if site.name is constant("PHP_EOL") %}x{% endif %}'],
            'closing delimiter inside a string' => ['{{ "}}" ~ 1..3 }}'],
            'string interpolation' => ['{{ "#{1..3}" }}'],
            'whitespace control' => ['{{- 1..3 -}}'],
        ];
    }

    public function test_template_identifiers_are_removed_as_documented(): void
    {
        $manager = app(ThemeManager::class);
        $theme = $this->upload($this->package(['templates/page.twig' => '<div id="hero" class="hero">{{ page.content }}</div>']));
        $manager->activate($theme['id']);
        $render = $manager->render(['title' => 'Services', 'type' => 'page'], ['name' => 'Trades'], '<p>Body</p>', '');
        $this->assertStringContainsString('<div class="hero">', $render['body']);
        $this->assertStringNotContainsString('id="hero"', $render['body']);
    }

    #[DataProvider('contextDependentSlots')]
    public function test_template_slots_are_validated_with_optional_fields_populated_and_empty(string $template): void
    {
        try {
            $this->upload($this->package(['templates/page.twig' => $template]));
            $this->fail('Slot rules must hold for populated and empty optional fields.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('page.content exactly once', $exception->errors()['file'][0]);
        }
    }

    public static function contextDependentSlots(): array
    {
        return [
            'populated phone duplicates content' => ['<div>{{ page.content }}{% if site.phone %}{{ page.content }}{% endif %}</div>'],
            'populated address duplicates contact' => ['<div>{{ page.content }}{% if site.address %}{{ widgets.contact }}{{ widgets.contact }}{% endif %}</div>'],
            'empty excerpt duplicates content' => ['<div>{{ page.content }}{% if not page.excerpt %}{{ page.content }}{% endif %}</div>'],
            'empty business name omits content' => ['<div>{% if site.name %}{{ page.content }}{% endif %}</div>'],
        ];
    }

    public function test_runtime_theme_contract_failure_falls_back_to_the_built_in_theme(): void
    {
        Exceptions::fake();
        $manager = app(ThemeManager::class);
        $theme = $this->upload($this->package([
            'templates/page.twig' => '<div class="drifted-theme">{{ page.content }}{% if settings.repeat %}{{ page.content }}{% endif %}</div>',
            'settings.schema.json' => json_encode(['type' => 'object', 'properties' => ['repeat' => ['type' => 'boolean', 'default' => false]]], JSON_THROW_ON_ERROR),
        ]));
        $manager->activate($theme['id']);
        $publishing = app(PublishingService::class);
        $page = $publishing->create(['title' => 'Drain repair', 'slug' => 'drain-repair', 'type' => 'service', 'content' => [['type' => 'paragraph', 'content' => 'Public drain copy']]], 'owner');
        $publishing->publish($page['id'], $page['version'], 'owner');
        $this->get('/p/drain-repair')->assertOk()->assertSee('drifted-theme');

        app(RecordRepository::class)->create('theme_settings', ['values' => ['repeat' => true]], $theme['id']);

        $this->get('/p/drain-repair')->assertOk()->assertSee('Public drain copy')->assertDontSee('drifted-theme');
        Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getPrevious() instanceof ValidationException);
    }

    #[DataProvider('malformedLayouts')]
    public function test_malformed_layout_json_is_rejected_as_a_validation_error(array $layout): void
    {
        $this->actingAs(new CmsUser(['id' => 'owner', 'name' => 'Owner', 'email' => 'owner@example.test', 'role' => 'owner']));
        $this->postJson('/api/v1/pages', ['title' => 'Drain repair', 'slug' => 'drain-repair', 'type' => 'service', 'layout' => $layout])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('layout');
    }

    public static function malformedLayouts(): array
    {
        $rule = ['selectors' => ['.hero'], 'style' => ['color' => 'red']];

        return [
            'array media text' => [['components' => [], 'styles' => [$rule + ['atRuleType' => 'media', 'mediaText' => ['(max-width: 640px)']]]]],
            'array tag name' => [['components' => [['type' => 'default', 'tagName' => ['div']]]]],
            'string attributes' => [['components' => [['type' => 'default', 'attributes' => 'class=hero']]]],
            'string classes' => [['components' => [['type' => 'default', 'classes' => 'hero']]]],
            'nested class name' => [['components' => [['type' => 'default', 'classes' => [['name' => ['hero']]]]]]],
            'array component type' => [['components' => [['type' => ['text']]]]],
            'string styles' => [['components' => [], 'styles' => '.hero{color:red}']],
            'integer selectors' => [['components' => [], 'styles' => [['selectors' => 5, 'style' => []]]]],
            'nested selector name' => [['components' => [], 'styles' => [['selectors' => [['name' => ['hero']]], 'style' => []]]]],
        ];
    }

    private function upload(UploadedFile $file): array
    {
        $theme = app(ThemeManager::class)->upload($file);
        $this->themePaths[] = storage_path('app/private/'.$theme['path']);

        return $theme;
    }

    private function package(array $overrides = []): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'cms-hardening-theme-');
        $this->temporaryPaths[] = $path;
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $entries = array_replace([
            'theme.json' => json_encode(['name' => 'Hardening theme', 'version' => '1.0.0', 'api_version' => 1, 'templates' => ['page' => 'templates/page.twig'], 'assets' => ['assets/style.css'], 'widgets' => ['contact']], JSON_THROW_ON_ERROR),
            'templates/page.twig' => '<div class="theme"><h1>{{ page.title }}</h1>{{ page.content }}{{ widgets.contact }}</div>',
            'assets/style.css' => '.theme{color:#23453b;padding:30px}',
            'LICENSE.txt' => 'MIT',
        ], $overrides);
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return new UploadedFile($path, 'theme.zip', 'application/zip', null, true);
    }
}

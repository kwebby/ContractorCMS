<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace Tests\Feature;

use App\Contracts\RecordRepository;
use App\Identity\CmsUser;
use App\Publishing\HomepageSections;
use App\Publishing\PublishingService;
use App\Publishing\ThemeManager;
use App\Publishing\ThemeSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use ZipArchive;

class ThemeCapabilitiesTest extends TestCase
{
    use RefreshDatabase;

    private array $paths = [];

    private array $directories = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['cms.data_provider' => 'postgres', 'cms.mfa_required' => false]);
        $this->actingAs(new CmsUser(['id' => 'owner', 'role' => 'owner', 'name' => 'Owner', 'email' => 'owner@example.test']));
    }

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }
        foreach ($this->directories as $directory) {
            File::deleteDirectory($directory);
        }
        parent::tearDown();
    }

    public function test_starter_supports_guided_content_without_duplicate_headings_or_owned_widgets(): void
    {
        $manager = app(ThemeManager::class);
        $theme = $this->starter();
        $manager->activate($theme['id']);
        $rendered = $manager->render([
            'type' => 'home', 'title' => 'Home', 'home_sections' => ['mode' => 'guided'],
        ], ['name' => 'Public business'], '<h1>One homepage heading</h1><form id="request-service">Owned form</form>', '', null, [
            'header' => '<header id="managed-header">Managed navigation</header>',
            'footer' => '<footer id="managed-footer">Canonical public identity</footer>',
        ]);
        $this->assertSame(1, substr_count($rendered['body'], '<h1>'));
        $this->assertSame(1, substr_count($rendered['body'], 'id="request-service"'));
        $this->assertSame(1, substr_count($rendered['body'], 'id="managed-header"'));
        $this->assertSame(1, substr_count($rendered['body'], 'id="managed-footer"'));
    }

    public function test_builtin_activation_and_rollback_are_reversible_and_idempotent(): void
    {
        $manager = app(ThemeManager::class);
        $theme = $this->starter();
        $manager->activate($theme['id']);
        $this->assertTrue($manager->listing()['can_rollback']);
        $manager->rollback();
        $this->assertNull($manager->listing()['active_theme']);
        $manager->rollback();
        $this->assertSame($theme['id'], $manager->listing()['active_theme']);
        $response = $this->postJson('/api/v1/themes/default')->assertOk()->json('data');
        $this->assertNull($response['active_id']);
        $this->assertSame($theme['id'], $response['previous_id']);
        $again = $manager->useDefault();
        $this->assertSame($response['version'], $again['version']);
        $manager->rollback();
        $this->assertSame($theme['id'], $manager->listing()['active_theme']);
        $this->actingAs(new CmsUser(['id' => 'client', 'role' => 'client']))->postJson('/api/v1/themes/default')->assertForbidden();
    }

    public function test_legacy_themes_cannot_activate_or_rollback_over_a_published_guided_homepage(): void
    {
        $manager = app(ThemeManager::class);
        $legacy = $this->upload($this->package());
        $manager->activate($legacy['id']);
        $modern = $this->starter();
        $manager->activate($modern['id']);
        $publishing = app(PublishingService::class);
        $page = $publishing->create(['title' => 'Home', 'slug' => 'home', 'type' => 'home', 'home_sections' => app(HomepageSections::class)->presets()['service']['document']], 'owner');
        $publishing->publish($page['id'], $page['version'], 'owner');
        foreach ([fn () => $manager->activate($legacy['id']), fn () => $manager->rollback()] as $operation) {
            try {
                $operation();
                $this->fail('An incompatible theme should remain inactive.');
            } catch (ValidationException) {
                $this->assertSame($modern['id'], $manager->listing()['active_theme']);
            }
        }
    }

    public function test_direct_publication_is_guarded_against_an_active_legacy_theme(): void
    {
        $theme = $this->upload($this->package());
        app(ThemeManager::class)->activate($theme['id']);
        $publishing = app(PublishingService::class);
        $page = $publishing->create(['title' => 'Home', 'slug' => 'home', 'type' => 'home', 'home_sections' => app(HomepageSections::class)->presets()['service']['document']], 'owner');
        try {
            $publishing->publish($page['id'], $page['version'], 'owner');
            $this->fail('Direct publication must enforce the theme capability.');
        } catch (ValidationException) {
            $this->assertNull(app(RecordRepository::class)->get('pages', $page['id'])['published']);
        }
    }

    public function test_settings_cannot_disable_a_required_managed_widget_and_preserve_last_good_values(): void
    {
        $theme = $this->upload($this->package(
            ['capabilities' => ['managed-navigation'], 'widgets' => ['header', 'footer']],
            '{% if settings.show_navigation %}{{ widgets.header }}{% endif %}{{ page.content }}{{ widgets.footer }}',
            ['settings.schema.json' => json_encode(['type' => 'object', 'properties' => ['show_navigation' => ['type' => 'boolean', 'default' => true]]], JSON_THROW_ON_ERROR)],
        ));
        app(ThemeManager::class)->activate($theme['id']);
        $settings = app(ThemeSettings::class);
        $current = $settings->update($theme['id'], ['show_navigation' => true], 0);
        try {
            $settings->update($theme['id'], ['show_navigation' => false], $current['version']);
            $this->fail('Required managed navigation must survive settings edits.');
        } catch (ValidationException) {
            $saved = $settings->get($theme['id']);
            $this->assertTrue($saved['values']['show_navigation']);
            $this->assertSame($current['version'], $saved['version']);
            $this->assertSame($theme['id'], app(ThemeManager::class)->listing()['active_theme']);
        }
    }

    #[DataProvider('invalidDeclarations')]
    public function test_capability_declarations_require_distinct_known_string_lists(array $overrides): void
    {
        $this->expectException(ValidationException::class);
        $this->upload($this->package($overrides));
    }

    public static function invalidDeclarations(): array
    {
        return [
            'null' => [['capabilities' => null]],
            'string' => [['capabilities' => 'guided-homepage']],
            'map' => [['capabilities' => ['enabled' => 'guided-homepage']]],
            'nested' => [['capabilities' => [['guided-homepage']]]],
            'duplicate' => [['capabilities' => ['guided-homepage', 'guided-homepage']]],
            'unknown' => [['capabilities' => ['custom-scripts']]],
            'widgets string' => [['widgets' => 'header']],
            'widgets duplicate' => [['widgets' => ['header', 'header']]],
            'navigation missing declarations' => [['capabilities' => ['managed-navigation'], 'widgets' => ['header']]],
        ];
    }

    #[DataProvider('brokenContracts')]
    public function test_declared_capabilities_are_verified_before_installation(array $manifest, string $template): void
    {
        $this->expectException(ValidationException::class);
        $this->upload($this->package($manifest, $template));
    }

    public static function brokenContracts(): array
    {
        return [
            'extra h1' => [['capabilities' => ['guided-homepage']], '<h1>Fixed heading</h1>{{ page.content }}'],
            'extra contact' => [['capabilities' => ['guided-homepage']], '{{ page.content }}{{ widgets.contact }}'],
            'missing managed header' => [['capabilities' => ['managed-navigation'], 'widgets' => ['header', 'footer']], '{{ page.content }}{{ widgets.footer }}'],
            'duplicate header' => [['capabilities' => ['managed-navigation'], 'widgets' => ['header', 'footer']], '{{ widgets.header }}{{ widgets.header }}{{ page.content }}{{ widgets.footer }}'],
            'content in attribute' => [[], '<div title="{{ page.content }}">Hidden content</div>'],
        ];
    }

    private function starter(): array
    {
        $path = app(ThemeManager::class)->starter();
        $this->paths[] = $path;

        return $this->upload(new UploadedFile($path, 'starter.zip', 'application/zip', null, true));
    }

    private function upload(UploadedFile $file): array
    {
        $theme = app(ThemeManager::class)->upload($file);
        $this->directories[] = storage_path('app/private/'.$theme['path']);

        return $theme;
    }

    private function package(array $manifest = [], string $template = '<div>{{ page.content }}{{ widgets.contact }}</div>', array $extraEntries = []): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'theme-capabilities-');
        $this->paths[] = $path;
        $archive = new ZipArchive;
        $archive->open($path, ZipArchive::OVERWRITE);
        $archive->addFromString('theme.json', json_encode(array_replace(['name' => 'Legacy', 'version' => '1.0.0', 'api_version' => 1, 'templates' => ['page' => 'templates/page.twig'], 'assets' => [], 'widgets' => ['contact']], $manifest), JSON_THROW_ON_ERROR));
        $archive->addFromString('templates/page.twig', $template);
        foreach ($extraEntries as $name => $contents) {
            $archive->addFromString($name, $contents);
        }
        $archive->close();

        return new UploadedFile($path, 'theme.zip', 'application/zip', null, true);
    }
}

<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace Tests\Feature;

use App\Contracts\RecordRepository;
use App\Publishing\HomepageSections;
use App\Publishing\PublishingService;
use App\Publishing\ThemeManager;
use App\Publishing\WebsiteSettings;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use ZipArchive;

class PublishingThemeTest extends TestCase
{
    use RefreshDatabase;

    private array $temporaryPaths = [];

    private array $themePaths = [];

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

    public function test_theme_upload_render_activation_and_rollback_preserve_versions(): void
    {
        $manager = app(ThemeManager::class);
        $theme = $manager->upload($this->archive());
        $this->themePaths[] = storage_path('app/private/'.$theme['path']);
        $this->assertSame('validated', $theme['status']);
        $this->assertSame('1.0.0', $theme['theme_version']);
        $manager->activate($theme['id']);
        $render = $manager->render(['title' => 'Our services', 'type' => 'page'], ['name' => '<img src=x onerror=alert(1)>'], '<p>Safe content</p>', '<form>Application widget</form>');
        $this->assertStringContainsString('<p>Safe content</p>', $render['body']);
        $this->assertStringContainsString('&lt;img', $render['body']);
        $this->assertStringNotContainsString('<img', $render['body']);
        $this->assertStringContainsString('<form>Application widget</form>', $render['body']);
        $this->assertSame(['/theme-assets/'.$theme['id'].'/assets/style.css'], $render['styles']);
        $second = $manager->upload($this->archive(['theme.json' => json_encode($this->manifest('Second theme'))]));
        $this->themePaths[] = storage_path('app/private/'.$second['path']);
        $manager->activate($second['id']);
        $this->assertSame($second['id'], $manager->listing()['active_theme']);
        $manager->rollback();
        $this->assertSame($theme['id'], $manager->listing()['active_theme']);
    }

    public function test_downloadable_starter_theme_passes_the_same_validation_as_user_themes(): void
    {
        $manager = app(ThemeManager::class);
        $path = $manager->starter();
        $this->temporaryPaths[] = $path;
        $theme = $manager->upload(new UploadedFile($path, 'workshop.zip', 'application/zip', null, true));
        $this->themePaths[] = storage_path('app/private/'.$theme['path']);
        $this->assertSame('Workshop', $theme['name']);
        $this->assertSame('validated', $theme['status']);
    }

    public function test_documented_starter_package_renders_published_pages_and_both_homepage_modes(): void
    {
        config(['cms.data_provider' => 'postgres']);
        $source = base_path('docs/themes/contractorcms-starter');
        $this->assertDirectoryExists($source);
        $path = tempnam(sys_get_temp_dir(), 'cms-documented-theme-');
        $this->temporaryPaths[] = $path;
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path, ZipArchive::OVERWRITE));
        foreach (File::allFiles($source) as $file) {
            $this->assertTrue($zip->addFile($file->getRealPath(), $file->getRelativePathname()));
        }
        $this->assertTrue($zip->close());

        $manager = app(ThemeManager::class);
        $theme = $manager->upload(new UploadedFile($path, 'contractorcms-starter.zip', 'application/zip', null, true));
        $this->themePaths[] = storage_path('app/private/'.$theme['path']);
        $this->assertSame('ContractorCMS Starter', $theme['name']);
        $this->assertSame('validated', $theme['status']);
        $manager->activate($theme['id']);
        $this->assertSame($theme['id'], $manager->listing()['active_theme']);

        Settings::put('business', ['name' => 'Starter contractor', 'phone' => '+1 416 555 0100']);
        $publishing = app(PublishingService::class);
        $service = $publishing->create([
            'title' => 'Repair services', 'slug' => 'repair-services', 'type' => 'service',
            'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Ordinary page content appears once.', 'styles' => []]]]],
        ], 'owner');
        $publishing->publish($service['id'], $service['version'], 'owner');
        $home = $publishing->create([
            'title' => 'Custom homepage', 'slug' => 'home', 'type' => 'home',
            'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Expert homepage content appears once.', 'styles' => []]]]],
        ], 'owner');
        $home = $publishing->publish($home['id'], $home['version'], 'owner');

        $website = app(WebsiteSettings::class);
        $site = $website->get();
        $draft = $site['draft'];
        $draft['navigation']['primary'] = [['label' => 'Explore repair services', 'kind' => 'page', 'page_id' => $service['id']]];
        $draft['navigation']['footer'] = [['label' => 'Our repair information', 'kind' => 'page', 'page_id' => $service['id']]];
        $site = $website->update($draft, $site['version'], 'owner');
        $website->publish($site['version'], 'owner');

        foreach (['/p/repair-services' => 'Ordinary page content appears once.', '/' => 'Expert homepage content appears once.'] as $url => $content) {
            $response = $this->get($url)->assertOk()->assertSee('contractorcms-starter')->assertSee('Explore repair services')->assertSee('Our repair information')->assertSee('href="/p/repair-services"', false)->assertSee('Local expertise. Personal service.');
            $body = $response->getContent();
            $this->assertSame(1, substr_count($body, '<p>'.$content.'</p>'));
            $this->assertSame(1, substr_count($body, 'class="site-header managed-header"'));
            $this->assertSame(1, substr_count($body, 'class="site-footer managed-footer"'));
            $this->assertSame(1, substr_count($body, '<h1'));
            $this->assertSame(1, substr_count($body, 'data-lead-form'));
        }

        $document = app(HomepageSections::class)->presets()['service']['document'];
        $document['sections'][0]['heading'] = 'Guided homepage heading';
        $document['sections'][0]['body'] = [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Guided homepage content appears once.', 'styles' => []]]]];
        $home = $publishing->update($home['id'], ['expected_version' => $home['version'], 'home_sections' => $document], 'owner');
        $publishing->publish($home['id'], $home['version'], 'owner');
        $response = $this->get('/')->assertOk()->assertSee('Guided homepage heading')->assertSee('Explore repair services')->assertSee('Our repair information')->assertDontSee('Expert homepage content appears once.')->assertDontSee('Local expertise. Personal service.');
        $body = $response->getContent();
        $this->assertSame(1, substr_count($body, '<p>Guided homepage content appears once.</p>'));
        $this->assertSame(1, substr_count($body, 'class="site-header managed-header"'));
        $this->assertSame(1, substr_count($body, 'class="site-footer managed-footer"'));
        $this->assertSame(1, substr_count($body, '<h1'));
        $this->assertSame(1, substr_count($body, 'data-lead-form'));
        $asset = $this->get('/theme-assets/'.$theme['id'].'/assets/style.css')->assertOk()->assertHeader('Content-Type', 'text/css; charset=utf-8');
        $this->assertStringContainsString('.starter-container', File::get($asset->baseResponse->getFile()->getPathname()));
    }

    #[DataProvider('maliciousEntries')]
    public function test_malicious_theme_archives_are_rejected(array $entries): void
    {
        $this->expectException(ValidationException::class);
        app(ThemeManager::class)->upload($this->archive($entries));
    }

    public static function maliciousEntries(): array
    {
        return [
            'traversal' => [['../escape.php' => '<?php die;']],
            'absolute' => [['/tmp/escape.txt' => 'bad']],
            'hidden config' => [['.htaccess' => 'Allow from all']],
            'PHP' => [['assets/install.php' => '<?php die;']],
            'JavaScript' => [['assets/script.js' => 'alert(1)']],
            'nested ZIP' => [['assets/theme.zip' => 'PK']],
            'script template' => [['templates/page.twig' => '<script>alert(1)</script>']],
            'sandbox function' => [['templates/page.twig' => '{{ system("id") }}']],
            'sandbox loop' => [['templates/page.twig' => '{% for n in 1..100000000 %}x{% endfor %}']],
            'raw filter' => [['templates/page.twig' => '{{ site.name|raw }}']],
            'external css' => [['assets/style.css' => '.hero{background:url(https://evil.test/a.png)}']],
            'CSS import' => [['assets/style.css' => '@import "https://evil.test/style.css";']],
            'CSS escape' => [['assets/style.css' => '@\\69mport "https://evil.test/style.css";']],
            'fake image' => [['assets/images/fake.png' => '<html>bad</html>']],
            'fake font' => [['assets/fonts/font.woff2' => 'not a font']],
            'manifest missing template' => [['theme.json' => '{"name":"Bad","version":"1.0.0","api_version":1,"templates":{"page":"templates/missing.twig"}}']],
            'case duplicate' => [['ASSETS/STYLE.CSS' => '.test{}']],
        ];
    }

    public function test_symlink_entry_is_rejected(): void
    {
        $file = $this->archive(['assets/images/link.png' => '/etc/passwd']);
        $zip = new ZipArchive;
        $zip->open($file->getRealPath());
        $zip->setExternalAttributesName('assets/images/link.png', ZipArchive::OPSYS_UNIX, 0120777 << 16);
        $zip->close();
        $this->expectException(ValidationException::class);
        app(ThemeManager::class)->upload($file);
    }

    public function test_failed_theme_install_leaves_active_theme_unchanged(): void
    {
        $records = app(RecordRepository::class);
        $records->create('settings', ['active_id' => 'previous', 'previous_id' => null], 'theme');
        try {
            app(ThemeManager::class)->upload($this->archive(['templates/page.twig' => '{{ constant("PHP_VERSION") }}']));
            $this->fail('The invalid theme should be rejected.');
        } catch (ValidationException) {
            $this->assertSame('previous', $records->get('settings', 'theme')['active_id']);
        }
    }

    private function archive(array $overrides = []): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'cms-theme-');
        $this->temporaryPaths[] = $path;
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $entries = array_replace([
            'theme.json' => json_encode($this->manifest()),
            'templates/page.twig' => '<div class="theme"><h1>{{ site.name }}</h1><h2>{{ page.title }}</h2>{{ page.content }}{{ widgets.contact }}</div>',
            'assets/style.css' => '.theme{color:#23453b;padding:30px}',
            'LICENSE.txt' => 'MIT',
        ], $overrides);
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return new UploadedFile($path, 'theme.zip', 'application/zip', null, true);
    }

    private function manifest(string $name = 'Simple theme'): array
    {
        return ['name' => $name, 'version' => '1.0.0', 'api_version' => 1, 'templates' => ['page' => 'templates/page.twig'], 'assets' => ['assets/style.css'], 'widgets' => ['contact']];
    }
}

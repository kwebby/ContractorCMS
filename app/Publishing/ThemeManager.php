<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace App\Publishing;

use App\Contracts\RecordRepository;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Twig\Environment;
use Twig\Error\SyntaxError;
use Twig\Extension\SandboxExtension;
use Twig\Loader\ArrayLoader;
use Twig\Sandbox\SecurityPolicy;
use Twig\Source;
use Twig\Token;
use ZipArchive;

final class ThemeManager
{
    /** Every public business field a template can read, populated for contract validation. */
    private const PREVIEW_SITE = [
        'name' => 'Preview Contracting', 'legal_name' => 'Preview Contracting Ltd.', 'phone' => '+1 555 0100', 'email' => 'office@example.com',
        'address' => '100 Main Street', 'city' => 'Springfield', 'region' => 'ON', 'postal_code' => 'A1A 1A1', 'country' => 'CA',
        'trade' => 'plumbing', 'service_areas' => 'Springfield and nearby communities',
    ];

    public function __construct(private readonly RecordRepository $records) {}

    public function listing(): array
    {
        $setting = $this->records->find('settings', 'theme');

        return ['data' => $this->records->all('themes'), 'active_theme' => $setting['active_id'] ?? null, 'can_rollback' => (bool) ($setting['has_previous'] ?? ! empty($setting['previous_id']))];
    }

    public function starter(): string
    {
        if (! class_exists(ZipArchive::class)) {
            $this->fail('The server requires the PHP ZIP extension.');
        }
        $path = tempnam(sys_get_temp_dir(), 'contractor-theme-');
        $archive = new ZipArchive;
        if ($archive->open($path, ZipArchive::OVERWRITE) !== true) {
            $this->fail('Unable to create the starter theme archive.');
        }
        $archive->addFromString('theme.json', json_encode([
            'name' => 'Workshop', 'version' => '1.1.0', 'api_version' => 1,
            'templates' => ['page' => 'templates/page.twig', 'home' => 'templates/home.twig'], 'assets' => ['assets/style.css'], 'widgets' => ['contact', 'header', 'footer'],
            'capabilities' => ['guided-homepage', 'managed-navigation'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $archive->addFromString('templates/page.twig', '<!-- Author: ramanpal singh | URL: https://kwebby.com -->{{ widgets.header }}<div class="workshop"><div class="workshop-hero"><p class="workshop-label">{{ settings.tagline|default("Home services")|upper }}</p><h1>{{ page.title }}</h1><p>{{ page.excerpt }}</p></div><div class="article-content">{{ page.content }}</div>{{ widgets.contact }}</div>{{ widgets.footer }}');
        $archive->addFromString('templates/home.twig', '<!-- Author: ramanpal singh | URL: https://kwebby.com -->{{ widgets.header }}<div class="workshop">{% if page.guided_homepage %}{{ page.content }}{% else %}<div class="workshop-hero"><p>{{ settings.tagline }}</p><h1>{{ page.title }}</h1><p>{{ page.excerpt }}</p></div>{{ page.content }}{{ widgets.contact }}{% endif %}</div>{{ widgets.footer }}');
        $archive->addFromString('assets/style.css', '/* Author: ramanpal singh | URL: https://kwebby.com */ .workshop{background:var(--website-background);color:var(--website-text)}.workshop-hero{padding:var(--website-spacing,64px) 6%;background:var(--website-surface)}.workshop-hero h1{max-width:900px;font:600 clamp(36px,5vw,64px)/1.12 var(--website-heading-font,sans-serif);letter-spacing:-1.5px}.workshop-hero p{max-width:650px;font-size:18px;line-height:1.7}.workshop-label{font-size:13px!important}.workshop .article-content{padding-top:30px}.workshop .home-button-primary{background:var(--theme-accent,var(--website-primary))}');
        $archive->addFromString('settings.schema.json', json_encode(['type' => 'object', 'properties' => ['accent' => ['type' => 'string', 'title' => 'Accent color', 'format' => 'color', 'default' => '#27483d'], 'tagline' => ['type' => 'string', 'title' => 'Tagline', 'default' => 'HOME SERVICES', 'maxLength' => 100]]]));
        $archive->addFromString('LICENSE.txt', 'MIT License. You may use, modify, and distribute this starter theme. Provided without warranty.');
        $archive->close();

        return $path;
    }

    public function upload(UploadedFile $file): array
    {
        if ($file->getSize() > 25 * 1024 * 1024) {
            $this->fail('Theme archive exceeds 25 MiB.');
        }
        if (! class_exists(ZipArchive::class)) {
            $this->fail('The server requires the PHP ZIP extension to install themes.');
        }
        $id = (string) Str::uuid();
        $directory = storage_path('app/private/themes/'.$id);
        $zip = new ZipArchive;
        if ($zip->open($file->getRealPath(), ZipArchive::RDONLY) !== true) {
            $this->fail('Upload a valid ZIP archive.');
        }
        try {
            if ($zip->numFiles > 2000) {
                $this->fail('A theme may contain at most 2,000 entries.');
            }
            $entries = [];
            $total = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entry = $zip->statIndex($index);
                if (! is_array($entry)) {
                    $this->fail('Unable to read ZIP entry.');
                }
                $name = $entry['name'];
                $this->validatePath($name);
                $key = strtolower(rtrim($name, '/'));
                if (isset($entries[$key])) {
                    $this->fail('Duplicate archive paths are not allowed.');
                }
                $entries[$key] = true;
                $zip->getExternalAttributesIndex($index, $system, $attributes);
                $mode = ($attributes >> 16) & 0170000;
                if ($mode !== 0 && $mode !== 0100000 && $mode !== 0040000) {
                    $this->fail('Symlinks and special files are prohibited.');
                }
                if (($entry['encryption_method'] ?? 0) !== 0) {
                    $this->fail('Encrypted archives are prohibited.');
                }
                $total += $entry['size'];
                if ($total > 100 * 1024 * 1024 || $entry['size'] > 10 * 1024 * 1024) {
                    $this->fail('Uncompressed theme size exceeds the allowed limit.');
                }
                if (str_ends_with($name, '/')) {
                    continue;
                }
                $this->validateExtension($name);
            }
            $manifestSource = $zip->getFromName('theme.json');
            if (! is_string($manifestSource) || strlen($manifestSource) > 100000) {
                $this->fail('A theme.json manifest is required.');
            }
            $manifest = $this->decode($manifestSource);
            $this->validateManifest($manifest, $entries);
            File::ensureDirectoryExists($directory, 0700);
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index);
                if (str_ends_with($name, '/')) {
                    continue;
                }
                $contents = $zip->getFromIndex($index);
                if (! is_string($contents)) {
                    $this->fail('Archive extraction failed.');
                }
                $this->validateContents($name, $contents);
                File::ensureDirectoryExists(dirname($directory.'/'.$name), 0700);
                File::put($directory.'/'.$name, $contents);
            }
            $this->validateTemplateContracts($manifest, $directory, $id);

            return $this->records->create('themes', [
                'name' => $manifest['name'], 'theme_version' => $manifest['version'], 'status' => 'validated',
                'manifest' => $manifest, 'path' => 'themes/'.$id, 'sha256' => hash_file('sha256', $file->getRealPath()),
            ], $id);
        } catch (\Throwable $exception) {
            File::deleteDirectory($directory);
            if ($exception instanceof ValidationException) {
                throw $exception;
            }
            $this->fail('Theme validation failed: '.Str::limit($exception->getMessage(), 180));
        } finally {
            $zip->close();
        }
    }

    public function activate(string $id): array
    {
        return $this->changeActiveTheme($id);
    }

    public function rollback(): array
    {
        return $this->changeActiveTheme(null, true);
    }

    public function useDefault(): array
    {
        return $this->changeActiveTheme(null);
    }

    /** Publish and activate share a lock, so a legacy theme cannot race a guided-page publication. */
    private function changeActiveTheme(?string $id, bool $rollback = false): array
    {
        return $this->records->transaction(function () use ($id, $rollback): array {
            $this->records->lock('publishing-slugs');
            $this->records->lock('theme-activation');
            $setting = $this->records->find('settings', 'theme');
            if ($rollback) {
                if (! ($setting['has_previous'] ?? ! empty($setting['previous_id']))) {
                    $this->fail('No previous theme is available.');
                }
                $id = $setting['previous_id'] ?? null;
            }
            if ($setting && ($setting['active_id'] ?? null) === $id) {
                return $setting;
            }
            if ($id !== null) {
                $theme = $this->records->get('themes', $id);
                abort_unless(($theme['status'] ?? '') === 'validated', 422, 'Only validated themes can be activated.');
                foreach ($this->records->all('pages', ['status' => 'published']) as $record) {
                    if (is_array($record['published'] ?? null)) {
                        $this->assertHomepageSupport($record['published'], $id);
                    }
                }
                $this->validateTemplateContracts($theme['manifest'], storage_path('app/private/'.$theme['path']), $id, app(ThemeSettings::class)->get($id)['values']);
                foreach (array_keys($theme['manifest']['templates']) as $type) {
                    $page = ['type' => $type, 'title' => 'Activation preview', 'excerpt' => 'Preview'];
                    $this->render($page, ['name' => 'Preview'], '<p>Preview content</p>', '<form>Contact form</form>', $id);
                }
                if (in_array('guided-homepage', $theme['manifest']['capabilities'] ?? [], true)) {
                    $this->render(['type' => 'home', 'title' => 'Preview', 'home_sections' => ['mode' => 'guided']], ['name' => 'Preview'], '<h1>Guided homepage</h1>', '', $id);
                }
            }
            $data = ['active_id' => $id, 'previous_id' => $setting['active_id'] ?? null, 'has_previous' => $setting !== null || $id !== null];

            return $setting ? $this->records->update('settings', 'theme', $data, $setting['version']) : $this->records->create('settings', $data, 'theme');
        });
    }

    public function assertHomepageSupport(array $page, ?string $themeId = null): void
    {
        $themeId ??= $this->records->find('settings', 'theme')['active_id'] ?? null;
        if (! $themeId || ($page['type'] ?? '') !== 'home' || ($page['home_sections']['mode'] ?? '') !== 'guided') {
            return;
        }
        $theme = $this->records->get('themes', $themeId);
        if (! in_array('guided-homepage', $theme['manifest']['capabilities'] ?? [], true)) {
            $this->fail('This theme does not support guided homepage sections. Activate the built-in theme or the current Workshop starter, or keep this page in expert mode.');
        }
    }

    public function render(array $page, array $business, string $content, string $contactWidget, ?string $themeId = null, array $managedWidgets = []): ?array
    {
        $themeId ??= $this->records->find('settings', 'theme')['active_id'] ?? null;
        if (! $themeId) {
            return null;
        }
        $this->assertHomepageSupport($page, $themeId);
        $theme = $this->records->get('themes', $themeId);
        $manifest = $theme['manifest'];
        $template = $manifest['templates'][$page['type'] ?? 'page'] ?? $manifest['templates']['page'];
        $token = 'CMSCONTENT'.Str::random(32);
        $widgetToken = 'CMSCONTACT'.Str::random(32);
        $headerToken = 'CMSHEADER'.Str::random(32);
        $footerToken = 'CMSFOOTER'.Str::random(32);
        $publicSite = array_intersect_key($business, array_flip(['name', 'legal_name', 'phone', 'email', 'address', 'city', 'region', 'postal_code', 'country', 'trade', 'service_areas']));
        $publicSite = array_filter($publicSite, fn (mixed $value): bool => is_scalar($value) || $value === null);
        $settings = app(ThemeSettings::class)->get($themeId);
        $source = File::get(storage_path('app/private/'.$theme['path'].'/'.$template));
        $body = $this->renderTemplate($source, [
            'site' => $publicSite,
            'settings' => $settings['values'],
            'page' => ['title' => $page['title'], 'excerpt' => $page['excerpt'] ?? '', 'content' => $token, 'locale' => $page['locale'] ?? 'en-US', 'guided_homepage' => ($page['home_sections']['mode'] ?? '') === 'guided'],
            'widgets' => ['contact' => $widgetToken, 'header' => $headerToken, 'footer' => $footerToken],
            'assets' => ['base' => '/theme-assets/'.$themeId.'/assets'],
        ]);
        $body = $this->purify($body, $themeId);
        $this->assertTemplateSlots($body, $manifest, [$token, $widgetToken, $headerToken, $footerToken], ($page['type'] ?? '') === 'home' && ($page['home_sections']['mode'] ?? '') === 'guided');
        $body = str_replace([$token, $widgetToken, $headerToken, $footerToken], [$content, $contactWidget, $managedWidgets['header'] ?? '', $managedWidgets['footer'] ?? ''], $body);
        if (strlen($body) > 2_000_000) {
            $this->fail('Rendered theme exceeds 2 MB.');
        }
        $styles = array_values(array_filter($manifest['assets'] ?? [], fn (string $asset): bool => str_ends_with($asset, '.css')));

        return ['body' => $body, 'settings_css' => app(ThemeSettings::class)->css($settings['schema'], $settings['values']), 'styles' => array_map(fn (string $asset): string => '/theme-assets/'.$themeId.'/'.$asset, $styles)];
    }

    public function validateSettings(string $id, array $values): void
    {
        $theme = $this->records->get('themes', $id);
        $this->validateTemplateContracts($theme['manifest'], storage_path('app/private/'.$theme['path']), $id, $values);
    }

    public function asset(string $id, string $path): array
    {
        $this->validatePath($path);
        abort_unless(str_starts_with($path, 'assets/'), 404);
        $theme = $this->records->get('themes', $id);
        abort_unless($theme['status'] === 'validated', 404);
        $fullPath = storage_path('app/private/'.$theme['path'].'/'.$path);
        abort_unless(is_file($fullPath), 404);
        $mime = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'css' => 'text/css', 'png' => 'image/png', 'jpg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'woff2' => 'font/woff2', default => null,
        };
        abort_if($mime === null, 404);

        return [$fullPath, $mime];
    }

    private function validatePath(string $path): void
    {
        if (strlen($path) > 200 || ! preg_match('~^[A-Za-z0-9_./-]+$~D', $path) || str_starts_with($path, '/') || str_contains($path, '..') || str_contains($path, '//') || preg_match('~(^|/)\.~', $path)) {
            $this->fail('Unsafe archive path.');
        }
    }

    private function validateExtension(string $path): void
    {
        if (in_array($path, ['theme.json', 'settings.schema.json', 'preview.webp', 'LICENSE.txt'], true)) {
            return;
        }
        if (preg_match('~^(templates/[a-zA-Z0-9_/-]+\.twig|(?:sections|pages)/[a-zA-Z0-9_/-]+\.json|assets/[a-zA-Z0-9_/-]+\.css|assets/images/[a-zA-Z0-9_/-]+\.(?:png|jpg|jpeg|webp)|assets/fonts/[a-zA-Z0-9_/-]+\.woff2)$~D', $path)) {
            return;
        }
        $this->fail('Prohibited theme file: '.$path);
    }

    private function validateManifest(array $manifest, array $entries): void
    {
        $this->validateStringList(array_key_exists('capabilities', $manifest) ? $manifest['capabilities'] : [], ['guided-homepage', 'managed-navigation'], 'capabilities');
        $this->validateStringList(array_key_exists('widgets', $manifest) ? $manifest['widgets'] : [], ['contact', 'header', 'footer'], 'widgets');
        if (in_array('managed-navigation', $manifest['capabilities'] ?? [], true) && array_diff(['header', 'footer'], $manifest['widgets'] ?? [])) {
            $this->fail('Managed navigation requires the header and footer widgets in the manifest.');
        }
        if (! is_string($manifest['name'] ?? null) || trim($manifest['name']) === '' || strlen($manifest['name']) > 100 || ! is_string($manifest['version'] ?? null) || ! preg_match('/^\d+\.\d+\.\d+(?:-[a-zA-Z0-9.-]+)?$/D', $manifest['version'] ?? '') || ($manifest['api_version'] ?? null) !== 1) {
            $this->fail('Manifest requires name, semantic version, and api_version: 1.');
        }
        if (! is_array($manifest['templates'] ?? null) || ! isset($manifest['templates']['page']) || count($manifest['templates']) > 20) {
            $this->fail('Manifest templates must include a page template.');
        }
        foreach ($manifest['templates'] as $template) {
            if (! is_string($template) || ! preg_match('~^templates/[a-zA-Z0-9_/-]+\.twig$~D', $template) || ! isset($entries[strtolower($template)])) {
                $this->fail('Manifest references a missing template.');
            }
        }
        if (! is_array($manifest['assets'] ?? []) || ! array_is_list($manifest['assets'] ?? []) || count($manifest['assets'] ?? []) > 100) {
            $this->fail('Manifest assets must be a list of at most 100 files.');
        }
        foreach ($manifest['assets'] ?? [] as $asset) {
            if (! is_string($asset) || ! str_starts_with($asset, 'assets/') || ! isset($entries[strtolower($asset)])) {
                $this->fail('Manifest references a missing asset.');
            }
        }
    }

    private function validateStringList(mixed $value, array $allowed, string $name): void
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > count($allowed)) {
            $this->fail('Theme '.$name.' must be a bounded list of supported values.');
        }
        $seen = [];
        foreach ($value as $entry) {
            if (! is_string($entry) || ! in_array($entry, $allowed, true) || in_array($entry, $seen, true)) {
                $this->fail('Theme '.$name.' contains an unknown or duplicate value.');
            }
            $seen[] = $entry;
        }
    }

    /**
     * Exercise both layout modes before a package becomes eligible for
     * activation. Optional public fields are rendered both populated and
     * empty, so a conditional cannot add or remove a slot only at runtime.
     */
    private function validateTemplateContracts(array $manifest, string $directory, string $id, ?array $settingsValues = null): void
    {
        $contexts = [
            ['site' => self::PREVIEW_SITE, 'excerpt' => 'Preview content'],
            ['site' => [], 'excerpt' => ''],
        ];
        $values = [];
        if (is_file($directory.'/settings.schema.json')) {
            $schema = app(ThemeSettings::class)->schema($this->decode(File::get($directory.'/settings.schema.json')));
            foreach ($schema['properties'] as $name => $field) {
                if (array_key_exists('default', $field)) {
                    $values[$name] = $field['default'];
                }
            }
        }
        $values = array_replace($values, $settingsValues ?? []);
        $templates = $manifest['templates'];
        if (in_array('guided-homepage', $manifest['capabilities'] ?? [], true)) {
            $templates['home'] ??= $templates['page'];
        }
        foreach ($templates as $type => $template) {
            $modes = $type === 'home' && in_array('guided-homepage', $manifest['capabilities'] ?? [], true) ? [false, true] : [false];
            foreach ($modes as $guided) {
                foreach ($contexts as $context) {
                    $tokens = array_map(fn (string $name): string => 'CMS'.$name.Str::random(32), ['CONTENT', 'CONTACT', 'HEADER', 'FOOTER']);
                    $html = $this->renderTemplate(File::get($directory.'/'.$template), [
                        'site' => $context['site'], 'settings' => $values,
                        'page' => ['title' => 'Preview', 'excerpt' => $context['excerpt'], 'content' => $tokens[0], 'locale' => 'en-US', 'guided_homepage' => $guided],
                        'widgets' => ['contact' => $tokens[1], 'header' => $tokens[2], 'footer' => $tokens[3]],
                        'assets' => ['base' => '/theme-assets/'.$id.'/assets'],
                    ]);
                    $this->assertTemplateSlots($this->purify($html, $id), $manifest, $tokens, $guided);
                }
            }
        }
    }

    /** The application inserts safe HTML only at standalone text-node boundaries. */
    private function assertTemplateSlots(string $body, array $manifest, array $tokens, bool $guided): void
    {
        [$content, $contact, $header, $footer] = $tokens;
        if (substr_count($body, $content) !== 1 || substr_count($body, $contact) > 1) {
            $this->fail('A template must render page.content exactly once and the contact widget at most once.');
        }
        if (in_array('managed-navigation', $manifest['capabilities'] ?? [], true) && (substr_count($body, $header) !== 1 || substr_count($body, $footer) !== 1)) {
            $this->fail('A managed-navigation template must render widgets.header and widgets.footer exactly once.');
        }
        if (substr_count($body, $header) > 1 || substr_count($body, $footer) > 1) {
            $this->fail('Managed header and footer widgets may only appear once.');
        }
        if ($guided && (substr_count($body, $contact) !== 0 || preg_match('/<h1(?:\s|>)/i', $body))) {
            $this->fail('A guided homepage template must leave its H1 and contact form to page.content.');
        }
        $document = new \DOMDocument;
        $previousErrors = libxml_use_internal_errors(true);
        $document->loadHTML('<!doctype html><html><body>'.$body.'</body></html>', LIBXML_NONET);
        $xpath = new \DOMXPath($document);
        foreach ($tokens as $token) {
            foreach ($xpath->query('//@*') as $attribute) {
                if (str_contains($attribute->nodeValue, $token)) {
                    libxml_clear_errors();
                    libxml_use_internal_errors($previousErrors);
                    $this->fail('Application content and widgets must appear as content, never as HTML attributes.');
                }
            }
        }
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrors);
    }

    private function validateContents(string $name, string $contents): void
    {
        $extension = pathinfo($name, PATHINFO_EXTENSION);
        if (in_array($extension, ['json', 'twig', 'css', 'txt'], true)) {
            if (strlen($contents) > 250000 || ! mb_check_encoding($contents, 'UTF-8') || str_contains($contents, "\0") || preg_match('/<\?(?:php|=)?/i', $contents)) {
                $this->fail('Theme text files must be safe UTF-8 and at most 250 KB.');
            }
        }
        if ($extension === 'json') {
            $decoded = $this->decode($contents);
            if ($name === 'settings.schema.json') {
                app(ThemeSettings::class)->schema($decoded);
            }
        }
        if ($extension === 'twig') {
            if (strlen($contents) > 50000 || preg_match('/<\s*(?:script|iframe|object|embed|meta|link|base|html|head|body)\b|\son[a-z]+\s*=|javascript\s*:/i', $contents) || $this->containsReservedTwigCode($contents)) {
                $this->fail('Template contains prohibited executable elements or expressions.');
            }
        }
        if ($extension === 'css') {
            $this->validateStylesheet($contents);
        }
        if (in_array($extension, ['png', 'jpg', 'jpeg', 'webp'], true)) {
            $details = @getimagesizefromstring($contents);
            $expected = match ($extension) {
                'png' => 'image/png', 'jpg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp'
            };
            if (! $details || $details['mime'] !== $expected || $details[0] * $details[1] > 24_000_000) {
                $this->fail('Invalid or oversized raster image.');
            }
        }
        if ($extension === 'woff2' && ! str_starts_with($contents, 'wOF2')) {
            $this->fail('Invalid WOFF2 font.');
        }
    }

    /**
     * Twig's own lexer separates template code from static text, so reserved
     * operators and names are rejected only where Twig would evaluate them.
     * Comments, verbatim text and string literals are never evaluated.
     */
    private function containsReservedTwigCode(string $source): bool
    {
        if (! preg_match('/\.\.|\*\*|matches|range|random|source|include|constant/i', $source)) {
            return false;
        }
        try {
            $stream = (new Environment(new ArrayLoader))->tokenize(new Source($source, 'theme'));
        } catch (SyntaxError $exception) {
            $this->fail('Template syntax is invalid: '.Str::limit($exception->getRawMessage(), 120));
        }
        while (! $stream->isEOF()) {
            $token = $stream->next();
            if ($token->test(Token::TEXT_TYPE) || $token->test(Token::STRING_TYPE)) {
                continue;
            }
            if (in_array(strtolower((string) $token->getValue()), ['..', '**', 'matches', 'range', 'random', 'source', 'include', 'constant'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Browsers fetch stylesheet references from url() tokens, from strings
     * inside URL-bearing functions and from custom properties substituted
     * into them. The scan follows CSS tokenization of comments, strings and
     * unquoted URLs, so a reference cannot hide from the validator.
     */
    private function validateStylesheet(string $css): void
    {
        if (preg_match('/\\\\|@import|expression\s*\(|behavior\s*:|-moz-binding|<|>/i', $css)) {
            $this->fail('CSS imports, escapes, and executable styles are prohibited.');
        }
        $blocks = [];
        $length = strlen($css);
        for ($offset = 0; $offset < $length; $offset++) {
            $character = $css[$offset];
            if ($character === '/' && ($css[$offset + 1] ?? '') === '*') {
                $end = strpos($css, '*/', $offset + 2);
                $offset = $end === false ? $length : $end + 1;
            } elseif ($character === '"' || $character === "'") {
                $end = strpos($css, $character, $offset + 1);
                $value = substr($css, $offset + 1, ($end === false ? $length : $end) - $offset - 1);
                if ($end === false || strpbrk($value, "\r\n\f") !== false) {
                    $this->fail('CSS strings must close on the line where they start.');
                }
                $this->validateStylesheetString($value, $blocks);
                $offset = $end;
            } elseif (preg_match('/\G[a-zA-Z0-9_\x80-\xff-]+/', $css, $word, 0, $offset)) {
                $offset += strlen($word[0]) - 1;
                if (($css[$offset + 1] ?? '') !== '(') {
                    continue;
                }
                $name = strtolower($word[0]);
                if ($name === 'url' && preg_match('/\G\(\s*+(?=[^"\'])/', $css, $opening, 0, $offset + 1)) {
                    $start = $offset + 1 + strlen($opening[0]);
                    $end = strpos($css, ')', $start);
                    $end = $end === false ? $length : $end;
                    $this->validateStylesheetUrl(rtrim(substr($css, $start, $end - $start)));
                    $offset = $end;

                    continue;
                }
                $blocks[] = $name;
                $offset++;
            } elseif (in_array($character, ['(', '[', '{'], true)) {
                $blocks[] = $character;
            } elseif ($blocks && ($character === ']' || $character === '}') && end($blocks) === ($character === ']' ? '[' : '{')) {
                array_pop($blocks);
            } elseif ($blocks && $character === ')' && ! in_array(end($blocks), ['[', '{'], true)) {
                array_pop($blocks);
            }
        }
    }

    /**
     * A string is a URL inside url(), src(), image(), image-set() and
     * cross-fade(). Elsewhere it may reach those functions through var(), so
     * it cannot look like an external or data URL. Attribute selector values
     * are compared with markup and are never fetched.
     *
     * @param  list<string>  $blocks
     */
    private function validateStylesheetString(string $value, array $blocks): void
    {
        foreach ($blocks as $block) {
            if (in_array(preg_replace('/^-[a-z]+-/', '', $block), ['url', 'src', 'image', 'image-set', 'cross-fade'], true)) {
                $this->validateStylesheetUrl($value);

                return;
            }
        }
        if (in_array('[', $blocks, true)) {
            return;
        }
        $reference = strtolower(trim(str_replace(["\t", "\n", "\r"], '', $value), "\x00..\x20"));
        if (preg_match('~^(?://|https?:|data:)~', $reference)) {
            $this->fail('CSS URLs must reference local images/ or fonts/ assets.');
        }
    }

    private function validateStylesheetUrl(string $url): void
    {
        $this->validatePath($url);
        if (! preg_match('~^(?:images|fonts)/[A-Za-z0-9_/-]+\.(?:png|jpe?g|webp|woff2)$~D', $url)) {
            $this->fail('CSS URLs must reference local images/ or fonts/ assets.');
        }
    }

    private function renderTemplate(string $source, array $context): string
    {
        $this->validateContents('templates/page.twig', $source);
        $twig = new Environment(new ArrayLoader(['page' => $source]), ['autoescape' => 'html', 'strict_variables' => false, 'cache' => false]);
        $twig->addExtension(new SandboxExtension(new SecurityPolicy(['if'], ['escape', 'e', 'default', 'upper', 'lower', 'title', 'trim'], [], [], []), true));
        $rendered = $twig->render('page', $context);
        if (strlen($rendered) > 1_000_000) {
            $this->fail('Rendered theme exceeds 1 MB.');
        }

        return $rendered;
    }

    private function purify(string $html, string $themeId): string
    {
        $configuration = \HTMLPurifier_Config::createDefault();
        $configuration->set('Cache.DefinitionImpl', null);
        $configuration->set('HTML.Allowed', 'div[class],span[class],p[class],a[href|title|class],h1[class],h2[class],h3[class],h4[class],h5[class],h6[class],ul[class],ol[class],li[class],strong,em,b,i,u,br,hr,img[src|alt|width|height|class],blockquote[class],table[class],thead,tbody,tr,th,td,dl,dt,dd');
        $configuration->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true, 'tel' => true]);
        $purified = (new \HTMLPurifier($configuration))->purify($html);
        $document = new \DOMDocument;
        $previousErrors = libxml_use_internal_errors(true);
        $document->loadHTML('<!doctype html><html><head><meta charset="utf-8"></head><body>'.$purified.'</body></html>', LIBXML_NONET);
        foreach (iterator_to_array($document->getElementsByTagName('img')) as $image) {
            $source = $image->getAttribute('src');
            if (! preg_match('~^/theme-assets/'.preg_quote($themeId, '~').'/assets/images/[a-zA-Z0-9_/-]+\.(?:png|jpe?g|webp)$~D', $source)) {
                $image->parentNode->removeChild($image);
            }
        }
        $body = $document->getElementsByTagName('body')->item(0);
        $result = '';
        foreach ($body->childNodes as $child) {
            $result .= $document->saveHTML($child);
        }
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrors);

        return $result;
    }

    private function decode(string $contents): array
    {
        try {
            $decoded = json_decode($contents, true, 20, JSON_THROW_ON_ERROR);
            if (! is_array($decoded)) {
                $this->fail('Theme JSON must be an object or array.');
            }

            return $decoded;
        } catch (\JsonException) {
            $this->fail('Invalid theme JSON.');
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}

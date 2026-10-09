<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace App\Http\Controllers;

use App\Automation\LeadToolService;
use App\Contracts\RecordRepository;
use App\Publishing\HomepageSections;
use App\Publishing\LayoutRenderer;
use App\Publishing\PublishingService;
use App\Publishing\SeoBuilder;
use App\Publishing\ThemeManager;
use App\Publishing\WebsitePresentation;
use App\Publishing\WebsiteSettings;
use App\Support\Access;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

final class PublishingController extends Controller
{
    public function __construct(
        private readonly RecordRepository $records,
        private readonly PublishingService $publishing,
        private readonly SeoBuilder $seo,
        private readonly ThemeManager $themes,
        private readonly LayoutRenderer $layouts,
    ) {}

    public function index(): JsonResponse
    {
        Access::authorize('publishing.manage');

        return response()->json(['data' => $this->records->all('pages')]);
    }

    public function show(string $id): JsonResponse
    {
        Access::authorize('publishing.manage');

        return response()->json(['data' => $this->records->get('pages', $id)]);
    }

    public function store(Request $request): JsonResponse
    {
        Access::authorize('publishing.manage');

        return response()->json(['data' => $this->publishing->create($request->all(), (string) $request->user()->id)], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        Access::authorize('publishing.manage');

        return response()->json(['data' => $this->publishing->update($id, $request->all(), (string) $request->user()->id)]);
    }

    public function publish(Request $request, string $id): JsonResponse
    {
        Access::authorize('publishing.manage');
        $data = $request->validate(['expected_version' => ['required', 'integer', 'min:1']]);
        $page = $this->records->get('pages', $id);
        $this->themes->assertHomepageSupport($page);

        return response()->json(['data' => $this->publishing->publish($id, $data['expected_version'], (string) $request->user()->id)]);
    }

    public function unpublish(Request $request, string $id): JsonResponse
    {
        Access::authorize('publishing.manage');
        $data = $request->validate(['expected_version' => ['required', 'integer', 'min:1']]);

        return response()->json(['data' => $this->publishing->unpublish($id, $data['expected_version'], (string) $request->user()->id)]);
    }

    public function revisions(string $id): JsonResponse
    {
        Access::authorize('publishing.manage');
        $this->records->get('pages', $id);

        return response()->json(['data' => $this->records->all('page_revisions', ['page_id' => $id])]);
    }

    public function archive(Request $request, string $id): JsonResponse
    {
        Access::authorize('publishing.manage');
        $data = $request->validate(['expected_version' => ['required', 'integer', 'min:1']]);

        return response()->json(['data' => $this->publishing->archive($id, $data['expected_version'], (string) $request->user()->id)]);
    }

    public function restoreRevision(Request $request, string $id, string $revisionId): JsonResponse
    {
        Access::authorize('publishing.manage');
        $data = $request->validate(['expected_version' => ['required', 'integer', 'min:1']]);
        $revision = $this->records->get('page_revisions', $revisionId);
        abort_unless($revision['page_id'] === $id, 404);

        return response()->json(['data' => $this->publishing->update($id, $revision['snapshot'] + $data, (string) $request->user()->id)]);
    }

    public function preview(string $id): Response
    {
        Access::authorize('publishing.manage');

        return $this->render($this->records->get('pages', $id), true);
    }

    public function home(): Response
    {
        foreach ($this->publishing->published() as $page) {
            if ($page['type'] === 'home') {
                return $this->render($page);
            }
        }

        return $this->render([
            'id' => 'home', 'type' => 'home', 'title' => 'Your home. In good hands.', 'slug' => 'home', 'locale' => 'en-US',
            'excerpt' => 'Tell us what needs attention. Our team will help you plan the next step.', 'content' => [], 'seo' => ['robots' => 'noindex,follow'], 'layout' => [],
        ]);
    }

    public function publicPage(string $slug): Response|RedirectResponse
    {
        foreach ($this->publishing->published() as $page) {
            if ($page['slug'] === $slug) {
                return $page['type'] === 'home' ? redirect('/', 301) : $this->render($page);
            }
        }
        $redirect = $this->records->find('redirects', hash('sha256', $slug));
        if ($redirect) {
            $target = $this->records->find('pages', $redirect['page_id']);
            if (($target['status'] ?? '') === 'published' && ! empty($target['published'])) {
                return redirect($this->seo->url($target['published']), 301);
            }
        }
        abort(404);
    }

    public function sitemap(): Response
    {
        $entries = array_map(fn (array $page): array => ['url' => $this->seo->url($page), 'modified' => substr($page['updated_at'] ?? $page['published_at'] ?? now()->toIso8601String(), 0, 10)], array_filter($this->publishing->published(), fn (array $page): bool => $this->seo->isIndexable($page)));
        if (app(LeadToolService::class)->available()) {
            foreach (array_keys(LeadToolService::TOOLS) as $slug) {
                $entries[] = ['url' => rtrim(config('app.url'), '/').'/tools/'.$slug, 'modified' => null];
            }
        }

        return response()->view('public.sitemap', ['entries' => $entries])->header('Content-Type', 'application/xml; charset=UTF-8');
    }

    public function robots(): Response
    {
        return response("User-agent: *\nAllow: /\nDisallow: /api/\nDisallow: /app/\nDisallow: /portal/\nDisallow: /tools/reports/\nDisallow: /install\nDisallow: /login\nDisallow: /register\nSitemap: ".rtrim(config('app.url'), '/')."/sitemap.xml\n", 200)->header('Content-Type', 'text/plain; charset=UTF-8');
    }

    public function themeIndex(): JsonResponse
    {
        Access::authorize('publishing.manage');

        return response()->json($this->themes->listing());
    }

    public function themeStarter(): BinaryFileResponse
    {
        Access::authorize('publishing.manage');

        return response()->download($this->themes->starter(), 'workshop-theme-1.1.0.zip', ['Content-Type' => 'application/zip'])->deleteFileAfterSend(true);
    }

    public function themeDefault(): JsonResponse
    {
        Access::authorize('publishing.manage');

        return response()->json(['data' => $this->themes->useDefault()]);
    }

    public function themeUpload(Request $request): JsonResponse
    {
        Access::authorize('publishing.manage');
        $request->validate(['file' => ['required', 'file', 'max:25600']]);

        return response()->json(['data' => $this->themes->upload($request->file('file'))], 201);
    }

    public function themeActivate(string $id): JsonResponse
    {
        Access::authorize('publishing.manage');

        return response()->json(['data' => $this->themes->activate($id)]);
    }

    public function themeRollback(): JsonResponse
    {
        Access::authorize('publishing.manage');

        return response()->json(['data' => $this->themes->rollback()]);
    }

    public function themePreview(string $id): Response
    {
        Access::authorize('publishing.manage');
        $page = $this->publishing->published()[0] ?? [
            'id' => 'preview', 'type' => 'page', 'title' => 'Theme preview', 'slug' => 'preview', 'locale' => 'en-US',
            'excerpt' => 'Preview this theme before activating it.', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Your published content appears here.', 'styles' => []]]]], 'seo' => [], 'layout' => [],
        ];

        return $this->render($page, true, $id);
    }

    public function themeAsset(string $id, string $path): BinaryFileResponse
    {
        [$file, $mime] = $this->themes->asset($id, $path);

        return response()->file($file, ['Content-Type' => $mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'public, max-age=31536000, immutable']);
    }

    public function publicAsset(string $extension): BinaryFileResponse
    {
        abort_unless(in_array($extension, ['css', 'js'], true), 404);

        return response()->file(resource_path('views/public/site.'.$extension), [
            'Content-Type' => $extension === 'css' ? 'text/css; charset=UTF-8' : 'application/javascript; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function homepageStyles(): Response
    {
        $css = file_get_contents(resource_path('views/public/website.css'));
        if (is_file(resource_path('views/public/home-sections.css'))) {
            $css .= "\n".file_get_contents(resource_path('views/public/home-sections.css'));
        }

        return response($css)->header('Content-Type', 'text/css; charset=UTF-8')->header('Cache-Control', 'public, max-age=3600');
    }

    public function websitePreview(): Response
    {
        Access::authorize('publishing.manage');
        $home = array_values(array_filter($this->publishing->published(), fn (array $page): bool => $page['type'] === 'home'))[0] ?? [
            'id' => 'home', 'type' => 'home', 'title' => 'Your home. In good hands.', 'slug' => 'home', 'locale' => 'en-US',
            'excerpt' => 'Tell us what needs attention. Our team will help you plan the next step.', 'content' => [], 'seo' => [], 'layout' => [],
        ];

        return $this->render($home, true, null, true);
    }

    public function diagnostics(): JsonResponse
    {
        Access::authorize('publishing.manage');
        $pages = $this->publishing->published();
        $issues = [];
        $titles = [];
        $descriptions = [];
        foreach ($pages as $page) {
            $metadata = $this->seo->build($page, $this->business());
            $titles[$metadata['title']][] = $page['id'];
            if ($metadata['description']) {
                $descriptions[$metadata['description']][] = $page['id'];
            } else {
                $issues[] = ['page_id' => $page['id'], 'type' => 'missing_description', 'message' => 'Add an excerpt or SEO description.'];
            }
            if (! $this->seo->isIndexable($page)) {
                $issues[] = ['page_id' => $page['id'], 'type' => 'excluded', 'message' => 'This page is intentionally excluded from the sitemap by robots or canonical settings.'];
            }
            $this->inspectLinks($page['content'] ?? [], $page['id'], $pages, $issues);
        }
        foreach (['title' => $titles, 'description' => $descriptions] as $field => $values) {
            foreach ($values as $ids) {
                if (count($ids) > 1) {
                    $issues[] = ['page_ids' => $ids, 'type' => 'duplicate_'.$field, 'message' => 'Published pages share the same '.$field.'.'];
                }
            }
        }

        return response()->json(['data' => $issues, 'published_count' => count($pages)]);
    }

    public function seoSettings(): JsonResponse
    {
        Access::authorize('publishing.manage');

        return response()->json(['data' => $this->records->find('settings', 'publishing') ?? []]);
    }

    public function updateSeoSettings(Request $request): JsonResponse
    {
        Access::authorize('publishing.manage');
        $data = $request->validate([
            'description' => ['nullable', 'string', 'max:500'],
            'og_image' => ['nullable', 'string', 'max:300'],
            'og_image_alt' => ['nullable', 'string', 'max:300'],
            'google_verification' => ['nullable', 'string', 'regex:/^[a-zA-Z0-9_-]{5,300}$/D'],
            'page_types' => ['sometimes', 'array', 'max:20'],
            'page_types.*.description' => ['nullable', 'string', 'max:500'],
            'page_types.*.og_image' => ['nullable', 'string', 'max:300'],
            'page_types.*.og_image_alt' => ['nullable', 'string', 'max:300'],
        ]);
        foreach ($data['page_types'] ?? [] as $type => $defaults) {
            $data['page_types'][$type] = array_intersect_key($defaults, array_flip(['description', 'og_image', 'og_image_alt']));
        }
        foreach (array_merge([$data], array_values($data['page_types'] ?? [])) as $settings) {
            if (! empty($settings['og_image'])) {
                $this->publishing->validate(['title' => 'SEO defaults', 'slug' => 'seo-defaults', 'seo' => ['og_image' => $settings['og_image']]], false);
            }
        }
        $record = $this->records->transaction(function () use ($data): array {
            $this->records->lock('publishing-settings');

            return $this->records->find('settings', 'publishing')
                ? $this->records->update('settings', 'publishing', $data)
                : $this->records->create('settings', $data, 'publishing');
        });

        return response()->json(['data' => $record]);
    }

    private function inspectLinks(array $nodes, string $pageId, array $pages, array &$issues): void
    {
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            if (($node['type'] ?? '') === 'link' && preg_match('~^/p/([a-z0-9-]+)$~', $node['href'] ?? '', $matches)) {
                $exists = array_filter($pages, fn (array $page): bool => $page['slug'] === $matches[1]);
                if (! $exists && ! $this->records->find('redirects', hash('sha256', $matches[1]))) {
                    $issues[] = ['page_id' => $pageId, 'type' => 'broken_internal_link', 'message' => 'No published page exists at '.$node['href']];
                }
            }
            $this->inspectLinks(array_values(array_filter($node, 'is_array')), $pageId, $pages, $issues);
        }
    }

    private function render(array $page, bool $preview = false, ?string $themeId = null, bool $websitePreview = false): Response
    {
        $siteSettings = app(WebsiteSettings::class);
        $website = $siteSettings->publicData($websitePreview);
        $locationId = $page['location_id'] ?? null;
        if (($page['type'] ?? '') === 'branch' && ! $locationId) {
            foreach ($website['locations'] ?? [] as $location) {
                if (($location['page_id'] ?? null) === ($page['id'] ?? null)) {
                    $locationId = $location['id'];
                    break;
                }
            }
        }
        $business = $siteSettings->business($websitePreview, $locationId);
        $business['logo_url'] = ! empty($website['appearance']['logo_media_id']) ? '/files/'.$website['appearance']['logo_media_id'] : null;
        if ($locationId) {
            $business['primary_business'] = $siteSettings->business($websitePreview);
            $business['primary_business']['logo_url'] = $business['logo_url'];
        }
        $business['seo'] = $this->records->find('settings', 'publishing') ?? [];
        $pages = $this->publishing->published();
        $presentation = app(WebsitePresentation::class);
        $primary = $website['navigation']['primary'] ?? [];
        $primaryLinks = $presentation->links($primary, $pages, $business);
        $footerLinks = $presentation->links($website['navigation']['footer'] ?? [], $pages, $business);
        $button = $website['navigation']['header_button'] ?? [];
        $headerButton = ['label' => $button['label'] ?? 'Request service', 'href' => $presentation->href($button, $pages, $business), 'style' => $button['style'] ?? 'primary'];
        $shared = compact('business', 'website', 'primaryLinks', 'footerLinks', 'headerButton');
        $managedHeader = view('public.managed-header', $shared)->render();
        $managedFooter = view('public.managed-footer', $shared)->render();
        $metadata = $this->seo->build($page, $business, $pages, $preview);
        $content = $this->publishing->renderer($preview)->render($page['content'] ?? []);
        $contactWidget = view('public.contact', ['business' => $business])->render();
        $toolLinks = app(LeadToolService::class)->available() ? LeadToolService::TOOLS : [];
        $guided = $page['type'] === 'home' && ($page['home_sections']['mode'] ?? '') === 'guided';
        if ($guided) {
            $tools = [];
            foreach ($toolLinks as $slug => $tool) {
                $tools[$slug] = $tool + ['url' => '/tools/'.$slug, 'label' => $tool['name']];
            }
            $layout = ['body' => app(HomepageSections::class)->render($page['home_sections'], ['business' => $business, 'pages' => $pages, 'preview' => $preview, 'contactWidget' => $contactWidget, 'toolLinks' => $tools]), 'css' => ''];
        } else {
            $layout = $this->layouts->render($page['layout'] ?? [], $content, $contactWidget);
        }
        $theme = $this->renderTheme($page, $business, $layout['body'], $guided ? '' : $contactWidget, $themeId, ['header' => $managedHeader, 'footer' => $managedFooter]);

        return response()->view('public.page', [
            'page' => $page, 'business' => $business, 'pages' => $pages, 'metadata' => $metadata,
            'content' => $layout['body'], 'layoutCss' => $layout['css'], 'theme' => $theme, 'preview' => $preview,
            'toolLinks' => $toolLinks, 'guided' => $guided, 'website' => $website,
            'websiteCss' => $presentation->css($website['appearance'] ?? []),
            'managedHeader' => $managedHeader, 'managedFooter' => $managedFooter,
        ])->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff')->header('X-Robots-Tag', $preview ? 'noindex, nofollow' : $metadata['robots']);
    }

    /**
     * A failure in the active theme is reported and the page falls back to the
     * built-in theme, so one broken template cannot take every public page down.
     * An explicitly requested candidate preview still surfaces its error.
     */
    private function renderTheme(array $page, array $business, string $body, string $contactWidget, ?string $themeId, array $managedWidgets): ?array
    {
        try {
            return $this->themes->render($page, $business, $body, $contactWidget, $themeId, $managedWidgets);
        } catch (Throwable $exception) {
            if ($themeId !== null) {
                throw $exception;
            }
            report(new RuntimeException('The active theme could not render page '.($page['id'] ?? 'unknown').'; the built-in theme was used.', 0, $exception));

            return null;
        }
    }

    private function business(): array
    {
        $business = app(WebsiteSettings::class)->business();
        $business['seo'] = $this->records->find('settings', 'publishing') ?? $business['seo'] ?? [];

        return $business;
    }
}

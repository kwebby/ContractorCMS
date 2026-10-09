<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace App\Http\Controllers;

use App\Automation\LeadToolService;
use App\Contracts\RecordRepository;
use App\Publishing\PublishingService;
use App\Publishing\SeoBuilder;
use App\Publishing\WebsitePresentation;
use App\Publishing\WebsiteSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class AiToolsController extends Controller
{
    public function __construct(private readonly LeadToolService $tools, private readonly RecordRepository $records, private readonly SeoBuilder $seo, private readonly WebsiteSettings $websiteSettings, private readonly WebsitePresentation $presentation, private readonly PublishingService $publishing) {}

    public function page(string $slug): Response
    {
        $slug = $this->tools->canonical($slug);
        $tool = LeadToolService::TOOLS[$slug];
        $website = $this->websiteSettings->publicData();
        $business = $this->websiteSettings->business();
        $business['seo'] = $this->records->find('settings', 'publishing') ?? [];
        $business['logo_url'] = ! empty($website['appearance']['logo_media_id']) ? '/files/'.$website['appearance']['logo_media_id'] : null;
        $pages = $this->publishing->published();
        $primaryLinks = $this->presentation->links($website['navigation']['primary'], $pages, $business);
        $footerLinks = $this->presentation->links($website['navigation']['footer'], $pages, $business);
        $button = $website['navigation']['header_button'] ?? [];
        $headerButton = ['label' => $button['label'] ?? 'Request service', 'href' => $this->presentation->href($button, $pages, $business), 'style' => $button['style'] ?? 'primary'];
        $shared = compact('business', 'website', 'primaryLinks', 'footerLinks', 'headerButton');
        $managedHeader = view('public.managed-header', $shared)->render();
        $managedFooter = view('public.managed-footer', $shared)->render();
        $websiteCss = $this->presentation->css($website['appearance']);
        $available = $this->tools->available();
        $metadata = $this->seo->build([
            'title' => $tool['name'], 'type' => 'tool', 'slug' => $slug, 'locale' => ($business['country'] ?? '') === 'CA' ? 'en-CA' : 'en-US', 'excerpt' => $tool['description'],
            'seo' => ['canonical' => rtrim(config('app.url'), '/').'/tools/'.$slug, 'robots' => $available ? 'index,follow' : 'noindex,follow', 'schema_type' => 'WebApplication'],
        ], $business, $pages);

        return response()->view('public.tool', compact('slug', 'tool', 'business', 'available', 'metadata', 'website', 'managedHeader', 'managedFooter', 'websiteCss'))
            ->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff')->header('X-Robots-Tag', $metadata['robots']);
    }

    public function generate(Request $request, string $slug): JsonResponse
    {
        $data = $request->validate([
            'details' => ['required', 'string', 'min:15', 'max:12000'],
            'request_id' => ['required', 'uuid'],
            'website' => ['nullable', 'string', 'max:0'],
        ]);
        abort_if(strlen($data['details']) > 16000, 422, 'Please shorten your input to 16 KB or less.');

        return response()->json(['data' => $this->tools->generate($slug, $data['details'], $data['request_id'], $this->visitor($request))]);
    }

    public function requestReport(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'], 'email' => ['required', 'email', 'max:254'],
            'marketing_consent' => ['required', 'boolean'], 'contact_requested' => ['required', 'boolean'],
        ]);
        $this->tools->requestReport($id, $this->visitor($request), $data);

        return response()->json(['message' => 'Check your email to confirm delivery of your brief.'], 202);
    }

    public function confirmPage(string $token): Response
    {
        abort_unless(preg_match('/^[a-zA-Z0-9]{64}$/D', $token), 404);

        return response()->view('public.report-confirm', ['token' => $token, 'confirmed' => false])->header('X-Robots-Tag', 'noindex, nofollow')->header('Referrer-Policy', 'no-referrer')->header('Cache-Control', 'no-store');
    }

    public function verifyReport(string $token): Response
    {
        abort_unless(preg_match('/^[a-zA-Z0-9]{64}$/D', $token), 404);
        $this->tools->verifyReport($token);

        return response()->view('public.report-confirm', ['token' => $token, 'confirmed' => true])->header('X-Robots-Tag', 'noindex, nofollow')->header('Referrer-Policy', 'no-referrer')->header('Cache-Control', 'no-store');
    }

    private function visitor(Request $request): string
    {
        return hash_hmac('sha256', $request->session()->getId(), (string) config('app.key'));
    }
}

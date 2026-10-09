<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace App\Http\Controllers;

use App\Publishing\FontLibrary;
use App\Publishing\HomepageSections;
use App\Publishing\LocalSeo;
use App\Publishing\WebsiteSettings;
use App\Support\Access;
use Illuminate\Http\Request;

final class WebsiteSettingsController extends Controller
{
    public function __construct(private readonly WebsiteSettings $website, private readonly LocalSeo $localSeo) {}

    public function show(): mixed
    {
        Access::authorize('publishing.manage');

        return response()->json(['data' => $this->website->get()]);
    }

    public function update(Request $request): mixed
    {
        Access::authorize('publishing.manage');
        $data = $request->validate(['expected_version' => ['required', 'integer', 'min:1'], 'draft' => ['required', 'array']]);

        return response()->json(['data' => $this->website->update($data['draft'], $data['expected_version'], $request->user()->id)]);
    }

    public function publish(Request $request): mixed
    {
        Access::authorize('publishing.manage');
        $data = $request->validate(['expected_version' => ['required', 'integer', 'min:1']]);

        return response()->json(['data' => $this->website->publish($data['expected_version'], $request->user()->id)]);
    }

    public function revisions(): mixed
    {
        Access::authorize('publishing.manage');

        return response()->json(['data' => $this->website->revisions()]);
    }

    public function restore(Request $request, string $id): mixed
    {
        Access::authorize('publishing.manage');
        $data = $request->validate(['expected_version' => ['required', 'integer', 'min:1']]);

        return response()->json(['data' => $this->website->restore($id, $data['expected_version'], $request->user()->id)]);
    }

    public function localSeo(): mixed
    {
        Access::authorize('publishing.manage');

        return response()->json(['data' => $this->localSeo->dashboard()]);
    }

    public function citations(): mixed
    {
        Access::authorize('publishing.manage');

        return response()->json(['data' => $this->localSeo->citations()]);
    }

    public function storeCitation(Request $request): mixed
    {
        Access::authorize('publishing.manage');

        return response()->json(['data' => $this->localSeo->save($request->all(), $request->user()->id)], 201);
    }

    public function updateCitation(Request $request, string $id): mixed
    {
        Access::authorize('publishing.manage');

        return response()->json(['data' => $this->localSeo->save($request->all(), $request->user()->id, $id)]);
    }

    public function exportCitations(): mixed
    {
        Access::authorize('publishing.manage');

        return response($this->localSeo->export(), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="local-seo-citations.csv"', 'Cache-Control' => 'private, no-store', 'X-Robots-Tag' => 'noindex']);
    }

    public function exportNap(): mixed
    {
        Access::authorize('publishing.manage');

        return response($this->localSeo->exportNap(), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="public-business-nap.csv"', 'Cache-Control' => 'private, no-store', 'X-Robots-Tag' => 'noindex']);
    }

    public function fonts(FontLibrary $fonts): mixed
    {
        Access::authorize('publishing.manage');

        return response()->json(['data' => $fonts->catalog()]);
    }

    public function presets(HomepageSections $sections): mixed
    {
        Access::authorize('publishing.manage');

        return response()->json(['data' => $sections->presets()]);
    }
}

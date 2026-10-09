<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace App\Http\Controllers;

use App\Publishing\ThemeSettings;
use App\Support\Access;
use App\Support\Outbox;
use Illuminate\Http\Request;

class ThemeSettingsController extends Controller
{
    public function show(string $id, ThemeSettings $settings): mixed
    {
        Access::authorize('publishing.manage');

        return response()->json(['data' => $settings->get($id)]);
    }

    public function update(Request $request, string $id, ThemeSettings $settings): mixed
    {
        Access::authorize('publishing.manage');
        $data = $request->validate(['values' => 'present|array|max:30', 'expected_version' => 'required|integer|min:0']);
        $result = $settings->update($id, $data['values'], $data['expected_version']);
        Outbox::audit('theme.settings_updated', ['theme_id' => $id]);

        return response()->json(['data' => $result]);
    }
}

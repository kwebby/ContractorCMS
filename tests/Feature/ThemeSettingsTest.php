<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace Tests\Feature;

use App\Identity\CmsUser;
use App\Publishing\ThemeManager;
use App\Publishing\ThemeSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ThemeSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_uploaded_theme_settings_validate_render_and_conflict_safely(): void
    {
        config(['cms.mfa_required' => false, 'cms.data_provider' => 'postgres']);
        $themes = app(ThemeManager::class);
        $path = $themes->starter();
        $theme = $themes->upload(new UploadedFile($path, 'theme.zip', 'application/zip', null, true));
        try {
            $this->actingAs(new CmsUser(['id' => 'owner', 'role' => 'owner']));
            $this->getJson('/api/v1/themes/'.$theme['id'].'/settings')->assertOk()->assertJsonPath('data.values.accent', '#27483d');
            $this->putJson('/api/v1/themes/'.$theme['id'].'/settings', ['expected_version' => 0, 'values' => ['accent' => '#123456', 'tagline' => 'Our local team']])->assertOk();
            $this->putJson('/api/v1/themes/'.$theme['id'].'/settings', ['expected_version' => 0, 'values' => []])->assertConflict();
            $this->putJson('/api/v1/themes/'.$theme['id'].'/settings', ['expected_version' => 1, 'values' => ['accent' => 'red; background:url(https://example.com)']])->assertUnprocessable();
            $render = $themes->render(['title' => 'Home', 'content' => []], ['name' => 'Trades'], '<p>Hello</p>', '', $theme['id']);
            $this->assertStringContainsString('--theme-accent:#123456', $render['settings_css']);
            $this->assertStringContainsString('OUR LOCAL TEAM', $render['body']);
            $this->actingAs(new CmsUser(['id' => 'client', 'role' => 'client']))->getJson('/api/v1/themes/'.$theme['id'].'/settings')->assertForbidden();
        } finally {
            File::deleteDirectory(storage_path('app/private/'.$theme['path']));
            @unlink($path);
        }
    }

    public function test_schema_cannot_fetch_references_or_embed_nested_objects(): void
    {
        $this->expectException(ValidationException::class);
        app(ThemeSettings::class)->schema(['type' => 'object', '$ref' => 'https://external.test/schema']);
    }
}

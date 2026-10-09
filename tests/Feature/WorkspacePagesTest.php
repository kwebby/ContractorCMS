<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace Tests\Feature;

use App\Contracts\RecordRepository;
use App\Identity\CmsUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WorkspacePagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cms.data_provider' => 'postgres', 'cms.mfa_required' => false]);
    }

    public function test_settings_categories_have_refreshable_pages_and_legacy_links_redirect(): void
    {
        $this->signIn('owner');
        foreach (['business', 'mail', 'payments', 'invoice-design', 'ai', 'security', 'health', 'email-templates'] as $category) {
            $this->get('/app/settings/'.$category)->assertOk()->assertInertia(fn (Assert $page) => $page
                ->component('Workspace')->where('section', 'settings')->where('settingsPage', $category));
            $this->get('/app/settings?tab='.$category)->assertRedirect('/app/settings/'.$category);
        }
        $this->get('/app/settings/unknown')->assertNotFound();
        $this->get('/app/settings?tab=unknown')->assertNotFound();
    }

    public function test_publishing_edit_pages_are_accessible_to_marketing_without_business_settings_access(): void
    {
        $this->signIn('marketing');
        $theme = app(RecordRepository::class)->create('themes', ['name' => 'Preview theme']);
        $this->get('/app/themes/upload')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('section', 'themes')->where('themePage', 'upload'));
        $this->get('/app/themes/'.$theme['id'].'/settings')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('themeId', $theme['id'])->where('themePage', 'settings'));
        $this->get('/app/website/seo')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('section', 'pages')->where('publishingPage', 'seo'));
        $this->get('/app/themes/missing/settings')->assertNotFound();
        $this->get('/app/settings/payments')->assertForbidden();
    }

    public function test_client_cannot_access_internal_settings_pages(): void
    {
        $this->signIn('client');
        foreach (['/app/settings/business', '/app/themes/upload', '/app/themes/anything/settings', '/app/website/seo'] as $url) {
            $this->get($url)->assertForbidden();
        }
    }

    private function signIn(string $role): void
    {
        $user = app(RecordRepository::class)->create('users', ['name' => 'Page reviewer', 'email' => $role.'@example.test', 'role' => $role, 'active' => true, 'email_verified_at' => now()->toISOString()]);
        $this->actingAs(new CmsUser($user));
    }
}

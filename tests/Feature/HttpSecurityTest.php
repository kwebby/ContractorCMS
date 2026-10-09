<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HttpSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_csrf_is_enforced_outside_the_test_environment(): void
    {
        $this->app['env'] = 'production';
        config(['cms.mfa_required' => false, 'cms.data_provider' => 'postgres']);
        $this->postJson('/api/v1/public/leads', ['name' => 'Attempt', 'email' => 'attempt@example.test', 'service' => 'Test'])->assertStatus(419);
    }

    public function test_security_headers_and_private_indexing_are_present(): void
    {
        config(['cms.data_provider' => 'postgres', 'cms.mfa_required' => false]);
        $this->get('/login')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Content-Security-Policy');
        $this->get('/')->assertOk()->assertHeader('Content-Security-Policy')->assertSee('nonce=', false);
    }
}

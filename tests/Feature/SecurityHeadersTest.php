<?php

namespace Tests\Feature;

use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    public function test_common_headers_on_home(): void
    {
        $this->get('/')
            ->assertHeader('Content-Security-Policy', "default-src 'self'; img-src 'self' https://i.ytimg.com https://yt3.ggpht.com; script-src 'self'; style-src 'self'; connect-src 'self'; frame-ancestors 'none'; form-action 'self' https://accounts.google.com; base-uri 'self'")
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()')
            ->assertHeaderMissing('X-Robots-Tag');
    }

    public function test_static_pages_are_indexable(): void
    {
        foreach (['/privacy', '/terms'] as $uri) {
            $res = $this->get($uri)->assertOk()->assertHeaderMissing('X-Robots-Tag');
            $this->assertStringNotContainsString('no-store', $res->headers->get('Cache-Control'));
        }
    }

    public function test_contact_is_noindex_and_no_store(): void
    {
        $res = $this->get('/contact');
        $res->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));
    }

    public function test_unknown_route_is_noindex_and_has_csp(): void
    {
        $res = $this->get('/p/unknown-key')->assertNotFound();
        $res->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $res->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));
        $this->assertNotNull($res->headers->get('Content-Security-Policy'));
    }
}

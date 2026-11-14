<?php

namespace Tests\Feature;

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Support\Facades\Vite;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    private ?string $hotFile = null;

    protected function tearDown(): void
    {
        if ($this->hotFile !== null && is_file($this->hotFile)) {
            unlink($this->hotFile);
        }
        $this->hotFile = null;

        if ($this->app?->maintenanceMode()->active()) {
            $this->app->maintenanceMode()->deactivate();
        }

        parent::tearDown();
    }

    /**
     * 実際の public/hot には触れず、一時ファイルを Vite の hot ファイルとして使う（#18）。
     */
    private function writeHotFile(string $url): void
    {
        $this->hotFile = tempnam(sys_get_temp_dir(), 'listmaker-hot-');
        file_put_contents($this->hotFile, $url);
        // TestCase::setUp の withoutVite() の代役は useHotFile を無視するので、本物の Vite に戻してから差し替える
        $this->withVite();
        Vite::clearResolvedInstance();
        Vite::useHotFile($this->hotFile);
    }

    /**
     * 実際の storage/framework/down を作らないよう、メンテナンスモードは array キャッシュに置く（#18）。
     */
    private function activateMaintenanceInMemory(): void
    {
        config(['app.maintenance.driver' => 'cache', 'app.maintenance.store' => 'array']);
        $this->app->maintenanceMode()->activate([]);
    }

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

    public function test_framework_413_response_has_security_headers(): void
    {
        // ValidatePostSize（グローバルミドルウェア）が返す 413 にもヘッダーが付くこと（prepend で登録）
        $res = $this->call('POST', '/contact', [], [], [], ['CONTENT_LENGTH' => PHP_INT_MAX]);

        $res->assertStatus(413);
        $res->assertHeader('Content-Security-Policy', SecurityHeaders::CSP);
        $res->assertHeader('Referrer-Policy', 'no-referrer');
        $res->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_maintenance_mode_503_has_security_headers(): void
    {
        $this->activateMaintenanceInMemory();

        $res = $this->get('/');

        $res->assertStatus(503);
        $res->assertHeader('Content-Security-Policy', SecurityHeaders::CSP);
        $res->assertHeader('Referrer-Policy', 'no-referrer');
        $res->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_local_hot_mode_allows_vite_dev_server_origin(): void
    {
        $this->app['env'] = 'local';
        $this->writeHotFile('http://localhost:5173');

        $csp = $this->get('/')->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("script-src 'self' http://localhost:5173;", $csp);
        $this->assertStringContainsString("style-src 'self' http://localhost:5173 'unsafe-inline';", $csp);
        $this->assertStringContainsString("connect-src 'self' http://localhost:5173 ws://localhost:5173;", $csp);
        $this->assertStringNotContainsString('*', $csp);
        $this->assertDoesNotMatchRegularExpression("/script-src[^;]*'unsafe-inline'/", $csp);
    }

    public function test_production_ignores_hot_file_and_keeps_strict_csp(): void
    {
        $this->app['env'] = 'production';
        $this->writeHotFile('http://localhost:5173');

        $this->get('/')->assertHeader('Content-Security-Policy', SecurityHeaders::CSP);
    }

    public function test_tests_do_not_touch_real_hot_or_down_files(): void
    {
        $hot = public_path('hot');
        $down = storage_path('framework/down');
        $before = [file_exists($hot), file_exists($down)];

        $this->app['env'] = 'local';
        $this->writeHotFile('http://localhost:5173');
        $this->assertStringContainsString('http://localhost:5173', $this->get('/')->headers->get('Content-Security-Policy'));
        $this->assertSame([$before[0], $before[1]], [file_exists($hot), file_exists($down)]);

        $this->activateMaintenanceInMemory();
        $this->get('/')->assertStatus(503);
        $this->assertSame($before, [file_exists($hot), file_exists($down)]);

        $this->app->maintenanceMode()->deactivate();
        $this->assertSame($before, [file_exists($hot), file_exists($down)]);
    }
}

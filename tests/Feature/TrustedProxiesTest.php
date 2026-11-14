<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TrustedProxiesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Route::get('/_ip', fn (Request $r) => $r->ip());
    }

    protected function tearDown(): void
    {
        TrustProxies::flushState();
        parent::tearDown();
    }

    private function ipSeenFrom(string $remote): string
    {
        return (string) $this->withServerVariables(['REMOTE_ADDR' => $remote])
            ->withHeader('X-Forwarded-For', '203.0.113.7')
            ->get('/_ip')->assertOk()->getContent();
    }

    public function test_proxies_are_not_trusted_by_default(): void
    {
        AppServiceProvider::trustProxiesFrom(null);
        $this->assertSame('10.0.0.1', $this->ipSeenFrom('10.0.0.1'));
    }

    public function test_empty_string_is_not_trusted(): void
    {
        AppServiceProvider::trustProxiesFrom('');
        $this->assertSame('10.0.0.1', $this->ipSeenFrom('10.0.0.1'));
    }

    public function test_listed_proxy_is_trusted(): void
    {
        AppServiceProvider::trustProxiesFrom('192.168.0.1, 10.0.0.0/8');
        $this->assertSame('203.0.113.7', $this->ipSeenFrom('10.0.0.1'));
    }

    public function test_wildcard_trusts_any_sender(): void
    {
        AppServiceProvider::trustProxiesFrom('*');
        $this->assertSame('203.0.113.7', $this->ipSeenFrom('10.0.0.1'));
    }

    public function test_unlisted_sender_is_not_trusted(): void
    {
        AppServiceProvider::trustProxiesFrom('10.0.0.2');
        $this->assertSame('10.0.0.1', $this->ipSeenFrom('10.0.0.1'));
    }

    public function test_config_reads_trusted_proxies_env(): void
    {
        $this->assertArrayHasKey('trusted_proxies', config('listmaker'));
    }
}

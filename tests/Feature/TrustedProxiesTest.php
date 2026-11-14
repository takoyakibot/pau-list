<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
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

    public function test_only_forwarded_for_and_proto_are_trusted(): void
    {
        Route::get('/_req', fn (Request $r) => $r->ip().'|'.($r->isSecure() ? 'https' : 'http').'|'.$r->getHost().'|'.$r->getPort());
        AppServiceProvider::trustProxiesFrom('10.0.0.1');
        $body = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
            ->withHeaders([
                'X-Forwarded-For' => '203.0.113.7',
                'X-Forwarded-Proto' => 'https',
                'X-Forwarded-Host' => 'evil.example',
                'X-Forwarded-Port' => '8443',
            ])
            ->get('http://localhost/_req')->assertOk()->getContent();
        [$ip, $scheme, $host, $port] = explode('|', (string) $body);
        $this->assertSame('203.0.113.7', $ip);
        $this->assertSame('https', $scheme);
        $this->assertSame('localhost', $host);
        $this->assertNotSame('8443', $port);
    }

    /** @return list<string> */
    private function warnings(callable $boot): array
    {
        $messages = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$messages) {
            if ($e->level === 'warning') {
                $messages[] = $e->message;
            }
        });
        $boot();

        return $messages;
    }

    public function test_wildcard_is_warned_in_production(): void
    {
        $provider = new AppServiceProvider($this->app);
        $this->assertSame([], $this->warnings(fn () => $provider->warnAboutTrustedProxies('*')));
        $this->app['env'] = 'production';
        $this->assertSame(['TRUSTED_PROXIES trusts every sender (*)'], $this->warnings(fn () => $provider->warnAboutTrustedProxies('*')));
    }

    public function test_invalid_entries_are_warned(): void
    {
        $provider = new AppServiceProvider($this->app);
        foreach (['10.0.0.1, 192.168.0.0/16, 2001:db8::/32, ::1', null, ''] as $valid) {
            $this->assertSame([], $this->warnings(fn () => $provider->warnAboutTrustedProxies($valid)), (string) $valid);
        }
        foreach (['proxy.example', '10.0.0.1, 10.0.0.0/33', '2001:db8::/129', '10.0.0.1/', '999.0.0.1', '10.0.0.0/8/1'] as $invalid) {
            $this->assertSame(
                ['TRUSTED_PROXIES has an entry that is not an IP address or CIDR range'],
                $this->warnings(fn () => $provider->warnAboutTrustedProxies($invalid)),
                $invalid,
            );
        }
    }

    public function test_symfony_keywords_are_warned_as_what_they_trust(): void
    {
        // Symfony replaces REMOTE_ADDR by the sender itself (trusts every sender, like *) and PRIVATE_SUBNETS /
        // private_ranges by every private range: they are not typos that trust nothing.
        $provider = new AppServiceProvider($this->app);
        $this->assertSame(
            ['TRUSTED_PROXIES trusts every sender (REMOTE_ADDR)'],
            $this->warnings(fn () => $provider->warnAboutTrustedProxies('REMOTE_ADDR')),
        );
        foreach (['PRIVATE_SUBNETS', '10.0.0.1, private_ranges'] as $value) {
            $this->assertSame(
                ['TRUSTED_PROXIES trusts every private network range (PRIVATE_SUBNETS)'],
                $this->warnings(fn () => $provider->warnAboutTrustedProxies($value)),
                $value,
            );
        }
        AppServiceProvider::trustProxiesFrom('REMOTE_ADDR');
        $this->assertSame('203.0.113.7', $this->ipSeenFrom('198.51.100.9'));
    }

    public function test_env_example_does_not_suggest_the_wildcard(): void
    {
        $lines = file(base_path('.env.example'));
        $i = array_search("TRUSTED_PROXIES=\n", $lines, true);
        $this->assertIsInt($i);
        $this->assertStringNotContainsString('*', $lines[$i - 1]);
    }
}

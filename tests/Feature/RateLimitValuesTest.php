<?php

namespace Tests\Feature;

use App\Support\ClientKey;
use App\Support\ContactLimits;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Fixes the spec 8.1 values (reviewed in Phase 4: unchanged). */
class RateLimitValuesTest extends TestCase
{
    /** @return list<array{string, int, int, bool}> name, maxAttempts, decaySeconds, keyed by IP */
    public static function limiters(): array
    {
        return [
            ['global', 120, 60, true], ['video-lookup', 30, 60, true], ['oauth', 10, 60, true], ['owner-unlisted', 5, 600, true],
            ['creation-start', 5, 3600, true], ['creation-step', 2, 1, false], ['creation-operation', 30, 60, false], ['proposal-post', 20, 600, true],
        ];
    }

    #[DataProvider('limiters')]
    public function test_named_limiter_values(string $name, int $max, int $decay, bool $byIp): void
    {
        $request = Request::create('/', server: ['REMOTE_ADDR' => '203.0.113.5']);
        $request->setLaravelSession(app('session.store'));
        $limit = RateLimiter::limiter($name)($request);
        $limit = is_array($limit) ? $limit[0] : $limit;
        $this->assertInstanceOf(Limit::class, $limit);

        $this->assertSame($max, $limit->maxAttempts);
        $this->assertSame($decay, $limit->decaySeconds);
        $this->assertStringNotContainsString('203.0.113.5', (string) $limit->key);
        if ($byIp) {
            $this->assertStringContainsString(ClientKey::for($request), (string) $limit->key);
        }
    }

    public function test_client_key_is_hmac_hex_without_the_ip(): void
    {
        $key = ClientKey::for(Request::create('/', server: ['REMOTE_ADDR' => '203.0.113.5']));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $key);
        $this->assertNotSame(hash('sha256', '203.0.113.5'), $key);
        $this->assertSame(hash_hmac('sha256', '203.0.113.5', (string) config('app.key')), $key);
    }

    public function test_client_key_does_not_fail_without_remote_addr(): void
    {
        $key = ClientKey::for(Request::create('/', server: ['REMOTE_ADDR' => '']));
        $this->assertSame(hash_hmac('sha256', 'unknown', (string) config('app.key')), $key);
    }

    public function test_contact_limit_values(): void
    {
        $request = Request::create('/contact', 'POST', server: ['REMOTE_ADDR' => '203.0.113.5']);
        $client = ClientKey::for($request);
        $this->assertSame([
            ['contact:m:'.$client, 3, 600],
            ['contact:d:'.$client, 10, 86400],
            ['contact:all', 50, 86400],
        ], app(ContactLimits::class)->limits($request));
    }
}

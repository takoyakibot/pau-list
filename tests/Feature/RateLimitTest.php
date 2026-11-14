<?php

namespace Tests\Feature;

use App\Support\ChannelFetchThrottle;
use App\Support\ClientKey;
use App\Support\ProposalStoreLimits;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Tests\Support\AssertsNoInlineCode;
use Tests\TestCase;

class RateLimitTest extends TestCase
{
    use AssertsNoInlineCode;

    public function test_global_limit_returns_japanese_429_without_inline_code(): void
    {
        for ($i = 0; $i < 120; $i++) {
            $this->get('/')->assertOk();
        }
        $res = $this->get('/')->assertStatus(429);
        $res->assertSee('アクセスが集中しています');
        $this->assertNoInlineCode($res->getContent());
    }

    public function test_json_429_message_is_japanese(): void
    {
        for ($i = 0; $i < 120; $i++) {
            $this->get('/');
        }
        $this->getJson('/')->assertStatus(429)
            ->assertJson(['message' => 'アクセスが集中しています。しばらく待ってから再度お試しください'])
            ->assertHeader('Retry-After');
    }

    public function test_channel_fetch_throttle_minute_and_hour(): void
    {
        $req = Request::create('/', 'GET', server: ['REMOTE_ADDR' => '203.0.113.1']);
        $other = Request::create('/', 'GET', server: ['REMOTE_ADDR' => '203.0.113.2']);
        $t = app(ChannelFetchThrottle::class);
        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue($t->attempt($req));
        }
        $this->assertFalse($t->attempt($req));
        $this->assertTrue($t->attempt($other));

        // 5 hits already; 25 more across minute windows reach the hourly cap of 30
        for ($n = 5; $n < 30; $n += 5) {
            $this->travel(61)->seconds();
            for ($i = 0; $i < 5; $i++) {
                $this->assertTrue($t->attempt($req), "hit $n+$i");
            }
        }
        $this->travel(61)->seconds();
        $this->assertFalse($t->attempt($req));

        // a refused attempt does not consume the minute budget; after an hour all clears
        $this->travel(3600)->seconds();
        $this->assertTrue($t->attempt($req));
    }

    public function test_413_and_405_render_japanese_pages(): void
    {
        config(['app.debug' => false]);
        $res = $this->call('POST', '/contact', [], [], [], ['CONTENT_LENGTH' => 65537])->assertStatus(413);
        $res->assertSee('送信できるデータの上限を超えています');
        $this->assertNoInlineCode($res->getContent());
        $res = $this->post('/privacy')->assertStatus(405);
        $res->assertSee('この操作はできません');
        $this->assertNoInlineCode($res->getContent());
    }

    public function test_channel_fetch_throttle_lock_timeout_returns_false(): void
    {
        $req = Request::create('/', 'GET', server: ['REMOTE_ADDR' => '203.0.113.9']);
        $lock = Cache::lock('channel-fetch-guard:'.ClientKey::for($req), 30);
        $this->assertTrue($lock->get());
        $this->assertFalse(app(ChannelFetchThrottle::class)->attempt($req));
        $lock->release();
    }

    public function test_body_over_64kb_is_413(): void
    {
        $this->call('POST', '/contact', [], [], [], ['CONTENT_LENGTH' => 65537])->assertStatus(413);
    }

    public function test_body_over_64kb_without_content_length_is_413(): void
    {
        $this->call('POST', '/contact', [], [], [], [], str_repeat('a', 65537))->assertStatus(413);
    }

    public function test_404_page_is_japanese(): void
    {
        // No database in this test class: a malformed key never reaches the lookup (PreviewScreenTest covers live/expired keys).
        $res = $this->get('/p/'.str_repeat('a', 42))->assertNotFound()->assertSee('ページが見つかりません');
        $res->assertSee('30 日');
    }

    public function test_error_views_are_japanese_and_have_no_inline_code(): void
    {
        config(['app.debug' => false]);
        foreach ([401, 403, 404, 405, 413, 419, 429, 500, 503, 418, 502] as $code) {
            Route::get('/_err/'.$code, fn () => abort($code));
            $res = $this->get('/_err/'.$code)->assertStatus($code);
            $this->assertNoInlineCode($res->getContent());
            $res->assertDontSee('Page Expired');
            $res->assertDontSee('Server Error');
            $res->assertDontSee('Service Unavailable');
            $res->assertSee('<html lang="ja"', false);
            $this->assertMatchesRegularExpression('/\p{Hiragana}/u', strip_tags($res->getContent()));
            // the real status code, never the "4xx"/"5xx" fallback name
            $this->assertMatchesRegularExpression('#<h1[^>]*>\s*'.$code.'\s*</h1>#', $res->getContent());
            $res->assertDontSee('4xx');
            $res->assertDontSee('5xx');
            $res->assertSee('トップへ戻る');
            $res->assertSee('href="'.route('home').'"', false);
        }
    }

    public function test_ipv6_addresses_in_one_64_share_the_global_limit(): void
    {
        for ($i = 0; $i < 120; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:1:2::'.dechex($i + 1)])->get('/')->assertOk();
        }
        $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:1:2:ffff::1'])->get('/')->assertStatus(429);
        $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:1:3::1'])->get('/')->assertOk();
    }

    public function test_missing_remote_addr_is_not_a_server_error(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => ''])->get('/')->assertOk();
    }

    public function test_only_client_key_reads_the_ip(): void
    {
        $readers = collect(File::allFiles(app_path()))
            ->filter(fn ($file) => str_contains($file->getContents(), '->ip()'))
            ->map(fn ($file) => str_replace('\\', '/', $file->getRelativePathname()))
            ->values()->all();
        $this->assertSame(['Support/ClientKey.php'], $readers);
    }

    public function test_proposal_store_limits_use_client_key(): void
    {
        $req = Request::create('/', 'POST', server: ['REMOTE_ADDR' => '203.0.113.5']);
        app(ProposalStoreLimits::class)->hit($req);
        $this->assertSame(1, RateLimiter::attempts('proposal-store:m:'.ClientKey::for($req)));
        $this->assertSame(1, RateLimiter::attempts('proposal-store:d:'.ClientKey::for($req)));
        $this->assertSame(0, RateLimiter::attempts('proposal-store:m:203.0.113.5'));
    }

    public function test_channel_fetch_throttle_uses_client_key(): void
    {
        $req = Request::create('/', 'GET', server: ['REMOTE_ADDR' => '203.0.113.6']);
        $this->assertTrue(app(ChannelFetchThrottle::class)->attempt($req));
        $this->assertSame(1, RateLimiter::attempts('channel-fetch:m:'.ClientKey::for($req)));
        $this->assertSame(0, RateLimiter::attempts('channel-fetch:m:'.hash('sha256', '203.0.113.6')));
    }
}

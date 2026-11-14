<?php

namespace Tests\Feature;

use App\Support\ChannelFetchThrottle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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
        $lock = Cache::lock('channel-fetch-guard:'.hash('sha256', '203.0.113.9'), 30);
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
        }
    }
}

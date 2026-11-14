<?php

namespace Tests\Feature;

use App\Services\YouTube\QuotaLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakesYouTube;
use Tests\TestCase;

class VideoLookupTest extends TestCase
{
    use FakesYouTube, RefreshDatabase;

    private const VID = 'abcdefghijk';

    private function ch(string $c = 'a'): string
    {
        return 'UC'.str_repeat($c, 22);
    }

    private function lookup(string $url, ?string $channel = null)
    {
        return $this->getJson(route('channels.video', $channel ?? $this->ch()).'?url='.urlencode($url));
    }

    public function test_returns_video_with_outside_flag(): void
    {
        $this->fakeVideos([$this->videoResource(self::VID, $this->ch('b'), ['title' => 'T', 'channelTitle' => 'Other'])]);
        $this->lookup('https://youtu.be/'.self::VID)->assertOk()->assertExactJson(['video' => [
            'id' => self::VID, 'title' => 'T', 'thumbnail_url' => 'https://i.ytimg.com/vi/'.self::VID.'/mqdefault.jpg',
            'channel_id' => $this->ch('b'), 'channel_title' => 'Other', 'published_at' => '2025-01-02T03:04:05Z', 'outside' => true,
        ]]);
        $this->lookup('https://youtu.be/'.self::VID, $this->ch('b'))->assertOk()->assertJsonPath('video.outside', false);
    }

    public function test_same_video_within_10_minutes_hits_cache(): void
    {
        $this->fakeVideos([$this->videoResource(self::VID, $this->ch())]);
        $this->lookup(self::VID)->assertOk();
        $this->lookup('https://www.youtube.com/watch?v='.self::VID)->assertOk();
        Http::assertSentCount(1);
        $this->assertSame(1, app(QuotaLedger::class)->used(QuotaLedger::READ));
        $this->travel(11)->minutes();
        $this->lookup(self::VID)->assertOk();
        Http::assertSentCount(2);
    }

    public function test_invalid_url_is_422_and_sends_nothing(): void
    {
        Http::fake();
        $msg = ['message' => '動画の URL を確認してください'];
        $this->getJson(route('channels.video', $this->ch()))->assertStatus(422)->assertExactJson($msg);
        $this->lookup('https://example.com/watch?v='.self::VID)->assertStatus(422)->assertExactJson($msg);
        $this->lookup('https://youtu.be/'.str_repeat('a', 200))->assertStatus(422);
        $this->getJson(route('channels.video', $this->ch()).'?url[]=x')->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_private_or_missing_video_is_404_and_cached(): void
    {
        $this->fakeVideos([$this->videoResource(self::VID, $this->ch(), ['privacy' => 'unlisted'])]);
        $msg = ['message' => 'この動画は削除または非公開になっています'];
        $this->lookup(self::VID)->assertNotFound()->assertExactJson($msg);
        $this->lookup('zzzzzzzzzzz')->assertNotFound()->assertExactJson($msg);
        $this->lookup(self::VID)->assertNotFound();
        Http::assertSentCount(2);
    }

    public function test_budget_exhausted_is_503(): void
    {
        app(QuotaLedger::class)->record(QuotaLedger::READ, 2000);
        $this->lookup(self::VID)->assertStatus(503)->assertExactJson(['message' => '本日の取得上限に達しました']);
    }

    public function test_api_failure_is_503_and_not_cached(): void
    {
        Http::swap(new Factory);
        Http::fake(fn () => Http::response('', 500));
        $this->lookup(self::VID)->assertStatus(503)
            ->assertExactJson(['message' => 'YouTube との通信に失敗しました。時間をおいて再度お試しください']);
        Http::swap(new Factory);
        $this->fakeVideos([$this->videoResource(self::VID, $this->ch())]);
        $this->lookup(self::VID)->assertOk();
    }

    public function test_rate_limited_after_30_per_minute(): void
    {
        $this->fakeVideos([$this->videoResource(self::VID, $this->ch())]);
        for ($i = 0; $i < 30; $i++) {
            $this->lookup(self::VID)->assertOk();
        }
        $this->lookup(self::VID)->assertStatus(429)
            ->assertJson(['message' => 'アクセスが集中しています。しばらく待ってから再度お試しください']);
    }
}

<?php

namespace Tests\Feature;

use App\Http\Controllers\ChannelController;
use App\Http\Controllers\OwnerEditController;
use App\Http\Middleware\RequireOwnerSession;
use App\Services\PublicVideoLookup;
use App\Services\YouTube\QuotaLedger;
use App\Support\OwnerSession;
use App\Support\ProposalKey;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesProposals;
use Tests\Support\FakesGoogle;
use Tests\Support\FakesYouTube;
use Tests\TestCase;

/**
 * S5 add-by-URL (proposals.video): public videos as for proposers, plus the logged-in owner's own unlisted
 * videos (user decision #9). Nothing unlisted ever goes into the shared `video-lookup:{id}` cache.
 */
class OwnerVideoLookupTest extends TestCase
{
    use CreatesProposals, FakesGoogle, FakesYouTube, RefreshDatabase;

    private const UNLISTED = 'unl00000001';

    private const OTHER = 'UCbbbbbbbbbbbbbbbbbbbbbb';

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-14 03:00:00'));
        $this->key = $this->createProposal();
    }

    private function lookup(string $url, array $headers = ['Sec-Fetch-Site' => 'same-origin'], ?string $key = null): TestResponse
    {
        return $this->getJson(route('proposals.video', $key ?? $this->key).'?url='.urlencode($url), $headers);
    }

    /** Owner-token videos.list first (it answers only requests with Authorization), then the API key one. */
    private function fakeBoth(array $ownerVisible, array $public = []): void
    {
        $this->fakeOwnerVideos($ownerVisible);
        $this->fakeVideos($public);
    }

    private function ownerRequests(): int
    {
        return Http::recorded(fn ($r) => $r->hasHeader('Authorization'))->count();
    }

    private function keyRequests(): int
    {
        return Http::recorded(fn ($r) => ! $r->hasHeader('Authorization'))->count();
    }

    public function test_owner_adds_own_unlisted_video_by_url_marked_like_the_loaded_list(): void
    {
        $unlisted = $this->videoResource(self::UNLISTED, $this->channelId, ['privacy' => 'unlisted', 'title' => '限定の動画', 'publishedAt' => '2026-11-12T00:00:00Z']);
        $this->fakeBoth([$unlisted], [$unlisted]);

        $res = $this->actAsOwner($this->channelId)->lookup('https://youtu.be/'.self::UNLISTED)->assertOk();
        $res->assertExactJson(['video' => [
            'id' => self::UNLISTED, 'title' => '限定の動画', 'thumbnail_url' => 'https://i.ytimg.com/vi/'.self::UNLISTED.'/mqdefault.jpg',
            'channel_id' => $this->channelId, 'channel_title' => 'My Channel', 'published_at' => '2026-11-12T00:00:00Z',
            'outside' => false, 'unlisted' => true,
        ]]);
        $this->assertStringNotContainsString('ya29.session-token', $res->getContent());

        // The public lookup (API key, read budget) found nothing; then one owner_read with the owner's token.
        $this->assertSame(1, $this->keyRequests());
        $this->assertSame(1, $this->ownerRequests());
        $this->assertSame('Bearer ya29.session-token', Http::recorded(fn ($r) => $r->hasHeader('Authorization'))->sole()[0]->header('Authorization')[0]);
        $this->assertSame(1, app(QuotaLedger::class)->used(QuotaLedger::READ));
        $this->assertSame(1, app(QuotaLedger::class)->used(QuotaLedger::OWNER_READ));

        // The shared cache holds only "not found"; the session's unlisted list is not touched; nothing in the DB.
        $this->assertSame(['found' => false], Cache::get(PublicVideoLookup::cacheKey(self::UNLISTED)));
        $this->assertFalse(session()->has(OwnerSession::UNLISTED_KEY));
        $this->assertSame(0, DB::table('channel_cache_videos')->count());
        $this->assertSame(0, DB::table('proposal_items')->where('video_id', self::UNLISTED)->count());
    }

    public function test_a_video_in_the_loaded_unlisted_list_is_answered_from_the_session(): void
    {
        Http::fake();
        $this->actAsOwner($this->channelId);
        app(OwnerSession::class)->rememberUnlisted($this->channelId, [[
            'id' => self::UNLISTED, 'title' => '限定の動画', 'thumbnail_url' => null, 'published_at' => '2026-11-12T00:00:00Z', 'unlisted' => true,
        ]], false);

        $this->lookup('https://www.youtube.com/watch?v='.self::UNLISTED)->assertOk()
            ->assertJsonPath('video.id', self::UNLISTED)
            ->assertJsonPath('video.unlisted', true)
            ->assertJsonPath('video.outside', false)
            ->assertJsonPath('video.title', '限定の動画');
        Http::assertNothingSent();
    }

    public function test_public_videos_are_answered_like_the_proposer_lookup(): void
    {
        $this->fakeBoth([], [
            $this->videoResource('pub00000001', $this->channelId),
            $this->videoResource('pub00000002', self::OTHER),
        ]);
        $this->actAsOwner($this->channelId);
        $this->lookup('pub00000001')->assertOk()->assertJsonPath('video.outside', false)->assertJsonPath('video.unlisted', false);
        $this->lookup('pub00000002')->assertOk()->assertJsonPath('video.outside', true)->assertJsonPath('video.unlisted', false);
        $this->assertSame(0, $this->ownerRequests());
        $this->assertSame(0, app(QuotaLedger::class)->used(QuotaLedger::OWNER_READ));
        $this->assertTrue(Cache::get(PublicVideoLookup::cacheKey('pub00000001'))['found']);
    }

    /** @return array<string, array{string, string}> */
    public static function rejected(): array
    {
        return [
            'another channel\'s unlisted' => ['unl00000002', 'UCbbbbbbbbbbbbbbbbbbbbbb|unlisted'],
            'own private' => ['prv00000001', 'UCaaaaaaaaaaaaaaaaaaaaaa|private'],
            'deleted / missing' => ['zzzzzzzzzzz', ''],
        ];
    }

    #[DataProvider('rejected')]
    public function test_other_unlisted_private_and_missing_videos_are_rejected(string $id, string $resource): void
    {
        $visible = [];
        if ($resource !== '') {
            [$channel, $privacy] = explode('|', $resource);
            $visible[] = $this->videoResource($id, $channel, ['privacy' => $privacy]);
        }
        $this->fakeBoth($visible, $visible);

        $this->actAsOwner($this->channelId)->lookup('https://youtu.be/'.$id)->assertNotFound()
            ->assertExactJson(['message' => OwnerEditController::LOOKUP_NOT_ADDABLE]);
        $this->assertSame('この動画は追加できません（削除・非公開、またはあなたのチャンネル以外の限定公開の動画です）', OwnerEditController::LOOKUP_NOT_ADDABLE);
        $this->assertSame(1, $this->ownerRequests());
        $this->assertSame(['found' => false], Cache::get(PublicVideoLookup::cacheKey($id)));
    }

    public function test_requires_the_owner_session_of_the_proposals_channel(): void
    {
        Http::fake();
        $this->lookup(self::UNLISTED)->assertStatus(401)->assertExactJson(['message' => RequireOwnerSession::NOTICE]);
        $this->actAsOwner($this->channelId, now()->toImmutable())->lookup(self::UNLISTED)->assertStatus(401);
        $this->actAsOwner(self::OTHER)->lookup(self::UNLISTED)->assertForbidden()
            ->assertExactJson(['message' => RequireOwnerSession::MISMATCH]);
        $this->actAsOwner($this->channelId)->lookup(self::UNLISTED, key: ProposalKey::generate())->assertNotFound();
        Http::assertNothingSent();
    }

    /** @return array<string, array{array<string, string>}> */
    public static function rejectedHeaders(): array
    {
        return [
            'no headers' => [[]],
            'cross-site' => [['Sec-Fetch-Site' => 'cross-site']],
            'same-site' => [['Sec-Fetch-Site' => 'same-site']],
        ];
    }

    #[DataProvider('rejectedHeaders')]
    public function test_rejects_other_than_same_origin_fetch_before_the_limiter(array $headers): void
    {
        $this->fakeBoth([], [$this->videoResource('pub00000001', $this->channelId)]);
        $this->actAsOwner($this->channelId);
        // More than the 30/min owner-video-lookup limit: rejected requests must not consume it.
        for ($i = 0; $i < 35; $i++) {
            $this->lookup('pub00000001', $headers)->assertForbidden();
        }
        $this->assertCount(0, Http::recorded());
        $this->lookup('pub00000001')->assertOk();
    }

    public function test_legacy_xhr_header_is_accepted(): void
    {
        $this->fakeBoth([], [$this->videoResource('pub00000001', $this->channelId)]);
        $this->actAsOwner($this->channelId)->lookup('pub00000001', ['X-Requested-With' => 'XMLHttpRequest'])->assertOk();
    }

    public function test_invalid_url_is_422_and_sends_nothing(): void
    {
        Http::fake();
        $this->actAsOwner($this->channelId)->lookup('https://example.com/watch?v='.self::UNLISTED)->assertStatus(422)
            ->assertExactJson(['message' => ChannelController::VIDEO_URL_INVALID]);
        Http::assertNothingSent();
    }

    public function test_owner_read_guard_skips_the_owner_call(): void
    {
        app(QuotaLedger::class)->record(QuotaLedger::OWNER_READ, 1500);
        $unlisted = $this->videoResource(self::UNLISTED, $this->channelId, ['privacy' => 'unlisted']);
        $this->fakeBoth([$unlisted], [$unlisted]);
        $this->actAsOwner($this->channelId)->lookup(self::UNLISTED)->assertStatus(503)
            ->assertExactJson(['message' => OwnerEditController::LOOKUP_UNLISTED_FAILED]);
        $this->assertSame(0, $this->ownerRequests());
    }

    public function test_owner_api_failure_is_503_and_keeps_the_login(): void
    {
        Http::fake(fn (Request $r) => $r->hasHeader('Authorization') ? Http::response(['error' => ['code' => 500, 'errors' => [['reason' => 'backendError']]]], 500) : null);
        $this->fakeVideos([]);
        $this->actAsOwner($this->channelId)->lookup(self::UNLISTED)->assertStatus(503)
            ->assertExactJson(['message' => OwnerEditController::LOOKUP_UNLISTED_FAILED]);
        $this->assertTrue(session()->has(OwnerSession::OWNER_KEY));
    }

    public function test_google_401_logs_the_owner_out(): void
    {
        Http::fake(fn (Request $r) => $r->hasHeader('Authorization') ? Http::response(['error' => ['code' => 401, 'errors' => [['reason' => 'authError']]]], 401) : null);
        $this->fakeVideos([]);
        $this->actAsOwner($this->channelId)->lookup(self::UNLISTED)->assertStatus(401)
            ->assertExactJson(['message' => RequireOwnerSession::NOTICE]);
        $this->assertFalse(session()->has(OwnerSession::OWNER_KEY));
    }

    public function test_rate_limited_per_owner_channel(): void
    {
        $this->fakeBoth([], [$this->videoResource('pub00000001', $this->channelId)]);
        for ($i = 0; $i < 30; $i++) {
            $this->actAsOwner($this->channelId)->lookup('pub00000001')->assertOk();
        }
        $this->actAsOwner($this->channelId)->lookup('pub00000001')->assertStatus(429);
    }

    public function test_read_budget_exhausted_falls_through_to_the_owner_token(): void
    {
        app(QuotaLedger::class)->record(QuotaLedger::READ, 2000);
        $unlisted = $this->videoResource(self::UNLISTED, $this->channelId, ['privacy' => 'unlisted']);
        $this->fakeBoth([$unlisted, $this->videoResource('pub00000001', self::OTHER)], [$unlisted]);
        $this->actAsOwner($this->channelId);
        $this->lookup(self::UNLISTED)->assertOk()->assertJsonPath('video.unlisted', true);
        $this->lookup('pub00000001')->assertOk()->assertJsonPath('video.unlisted', false)->assertJsonPath('video.outside', true);
        $this->assertSame(0, $this->keyRequests());
        $this->assertSame(2, $this->ownerRequests());
        $this->assertNull(Cache::get(PublicVideoLookup::cacheKey('pub00000001')));
    }

    public function test_read_budget_and_owner_guard_both_exhausted_is_503_budget(): void
    {
        app(QuotaLedger::class)->record(QuotaLedger::READ, 2000);
        app(QuotaLedger::class)->record(QuotaLedger::OWNER_READ, 1500);
        $this->fakeBoth([], []);
        $this->actAsOwner($this->channelId)->lookup(self::UNLISTED)->assertStatus(503)
            ->assertExactJson(['message' => '本日の取得上限に達しました']);
        $this->assertCount(0, Http::recorded());
    }

    public function test_public_video_seen_only_by_the_owner_token_is_returned_but_not_cached(): void
    {
        // The shared cache still says "not found" (e.g. the owner made it public a moment ago).
        Cache::put(PublicVideoLookup::cacheKey('pub00000003'), ['found' => false], 600);
        $this->fakeBoth([$this->videoResource('pub00000003', self::OTHER, ['title' => '公開になった動画', 'channelTitle' => 'Other'])], []);
        $this->actAsOwner($this->channelId)->lookup('pub00000003')->assertOk()->assertExactJson(['video' => [
            'id' => 'pub00000003', 'title' => '公開になった動画', 'thumbnail_url' => 'https://i.ytimg.com/vi/pub00000003/mqdefault.jpg',
            'channel_id' => self::OTHER, 'channel_title' => 'Other', 'published_at' => '2025-01-02T03:04:05Z',
            'outside' => true, 'unlisted' => false,
        ]]);
        $this->assertSame(['found' => false], Cache::get(PublicVideoLookup::cacheKey('pub00000003')));
    }

    public function test_owner_token_step_is_limited_to_10_per_10_minutes_per_channel(): void
    {
        $logs = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logs) {
            if ($e->message === 'Rate limit exceeded') {
                $logs[] = $e->context;
            }
        });
        $this->fakeBoth([], [$this->videoResource('pub00000001', $this->channelId)]);
        for ($i = 0; $i < 10; $i++) {
            $this->actAsOwner($this->channelId)->lookup('zzzzzzzzzzz')->assertNotFound();
        }
        $res = $this->actAsOwner($this->channelId)->lookup('zzzzzzzzzzz')->assertStatus(429)
            ->assertExactJson(['message' => '動画の確認が続いています。10 分ほど待ってから再度お試しください']);
        $this->assertNotNull($res->headers->get('Retry-After'));
        $this->assertSame([['limiter' => 'owner-video-check']], $logs);
        $this->assertSame(10, $this->ownerRequests());
        // The session list and public steps are not limited by it.
        $this->actAsOwner($this->channelId)->lookup('pub00000001')->assertOk();
        // Another channel has its own count.
        $this->actAsOwner(self::OTHER)->lookup('zzzzzzzzzzz', key: $this->createProposal(['channel_id' => self::OTHER]))->assertNotFound();
        $this->travel(11)->minutes();
        $this->actAsOwner($this->channelId)->lookup('zzzzzzzzzzz')->assertNotFound();
    }

    public function test_proposer_lookup_of_an_unlisted_video_is_still_rejected_and_caches_nothing_unlisted(): void
    {
        $unlisted = $this->videoResource(self::UNLISTED, $this->channelId, ['privacy' => 'unlisted', 'title' => '限定の動画']);
        $this->fakeBoth([$unlisted], [$unlisted]);

        // Even with an owner session, the proposer route never uses the owner's token.
        $this->actAsOwner($this->channelId)
            ->getJson(route('channels.video', $this->channelId).'?url='.self::UNLISTED, ['Sec-Fetch-Site' => 'same-origin'])
            ->assertNotFound()->assertExactJson(['message' => ChannelController::VIDEO_NOT_ADDABLE]);
        $this->assertSame(0, $this->ownerRequests());

        // The owner adds it on S5 afterwards: the shared key still says only "not found" and has no title.
        $this->lookup(self::UNLISTED)->assertOk()->assertJsonPath('video.unlisted', true);
        $this->assertSame(['found' => false], Cache::get(PublicVideoLookup::cacheKey(self::UNLISTED)));
        $this->getJson(route('channels.video', $this->channelId).'?url='.self::UNLISTED, ['Sec-Fetch-Site' => 'same-origin'])
            ->assertNotFound()->assertJsonMissingPath('video');
        $this->assertStringNotContainsString('限定の動画', serialize(Cache::get(PublicVideoLookup::cacheKey(self::UNLISTED))));
    }
}

<?php

namespace Tests\Feature;

use App\Http\Controllers\OwnerEditController;
use App\Services\YouTube\QuotaLedger;
use App\Services\YouTube\YouTubeReader;
use App\Support\OwnerLogin;
use App\Support\OwnerSession;
use App\Support\QuotaPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Support\CreatesProposals;
use Tests\Support\FakesYouTube;
use Tests\TestCase;

class OwnerUnlistedVideosTest extends TestCase
{
    use CreatesProposals, FakesYouTube, RefreshDatabase;

    private const UPLOADS = 'UUaaaaaaaaaaaaaaaaaaaaaa';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-14 03:00:00'));
    }

    private function fetchUnlisted(array $headers = ['Sec-Fetch-Site' => 'same-origin']): TestResponse
    {
        return $this->getJson(route('owner.unlisted'), $headers);
    }

    private function fakeOwnerUploads(array $pages): void
    {
        $this->fakeUploads(self::UPLOADS, $pages);
    }

    private function playlistRequests(): int
    {
        return Http::recorded(fn ($r) => str_starts_with($r->url(), YouTubeReader::BASE_URL.'playlistItems?'))->count();
    }

    public function test_returns_only_the_owners_unlisted_uploads_marked_unlisted(): void
    {
        $this->fakeOwnerUploads([[
            $this->uploadItem('unl00000001', '2026-11-12T00:00:00Z', 'unlisted', '<b>限定の動画</b>'),
            $this->uploadItem('prv00000001', '2026-11-13T00:00:00Z', 'private', '非公開の動画'),
            $this->uploadItem('pub00000001', '2026-11-10T00:00:00Z'),
        ]]);
        $res = $this->actAsOwner($this->channelId)->fetchUnlisted()->assertOk();
        $res->assertExactJson(['videos' => [
            ['id' => 'unl00000001', 'title' => '<b>限定の動画</b>', 'thumbnail_url' => 'https://i.ytimg.com/vi/unl00000001/mqdefault.jpg', 'published_at' => '2026-11-12T00:00:00Z', 'unlisted' => true],
        ]]);
        $this->assertStringNotContainsString('ya29.session-token', $res->getContent());
        $this->assertSame(1, app(QuotaLedger::class)->used(QuotaLedger::OWNER_READ));
        $this->assertSame(0, app(QuotaLedger::class)->used(QuotaLedger::READ));
        $req = Http::recorded()->sole()[0];
        $this->assertSame('Bearer ya29.session-token', $req->header('Authorization')[0]);
        $this->assertStringStartsWith(YouTubeReader::BASE_URL.'playlistItems?', $req->url());
        $this->assertSame(0, DB::table('channel_caches')->count());
        $this->assertSame(0, DB::table('channel_cache_videos')->count());
    }

    public function test_rejects_cross_site_requests(): void
    {
        $this->fakeOwnerUploads([[]]);
        $this->actAsOwner($this->channelId)->fetchUnlisted(['Sec-Fetch-Site' => 'cross-site'])->assertForbidden();
        $this->assertCount(0, Http::recorded());
    }

    public function test_requires_an_unexpired_owner_login(): void
    {
        $this->fakeOwnerUploads([[]]);
        $this->fetchUnlisted()->assertStatus(401)
            ->assertExactJson(['message' => 'ログインの有効期限が切れたか、ログインしていません。もう一度ログインしてください']);
        $this->actAsOwner($this->channelId, now()->toImmutable())->fetchUnlisted()->assertStatus(401);
        $this->assertCount(0, Http::recorded());
    }

    public function test_result_is_cached_in_the_session_for_10_minutes_per_channel(): void
    {
        $this->fakeOwnerUploads([[$this->uploadItem('unl00000001', '2026-11-12T00:00:00Z', 'unlisted')]]);
        $this->actAsOwner($this->channelId, now()->toImmutable()->addHour());
        $this->fetchUnlisted()->assertOk();
        $this->fetchUnlisted()->assertOk()->assertJsonPath('videos.0.id', 'unl00000001');
        $this->assertSame(1, $this->playlistRequests());
        $this->assertSame($this->channelId, session(OwnerSession::UNLISTED_KEY)['channel_id']);

        $this->travel(601)->seconds();
        $this->fetchUnlisted()->assertOk();
        $this->assertSame(2, $this->playlistRequests());
    }

    public function test_cache_is_bound_to_the_logged_in_channel(): void
    {
        $other = 'UC'.str_repeat('z', 22);
        $this->fakeOwnerUploads([[$this->uploadItem('unl00000001', '2026-11-12T00:00:00Z', 'unlisted')]]);
        $this->fakeUploads('UU'.str_repeat('z', 22), [[]]);
        $this->actAsOwner($this->channelId)->fetchUnlisted()->assertOk();
        $this->actAsOwner($other)->fetchUnlisted()->assertOk()->assertExactJson(['videos' => []]);
        $this->assertSame(2, $this->playlistRequests());
    }

    public function test_login_and_forget_clear_the_cache(): void
    {
        $owners = app(OwnerSession::class);
        $owners->rememberUnlisted($this->channelId, [['id' => 'unl00000001']]);
        $this->assertNotNull($owners->unlisted($this->channelId));
        $owners->login(new OwnerLogin('tok', now()->toImmutable()->addHour(), $this->channelId, 'A'));
        $this->assertNull($owners->unlisted($this->channelId));
        $this->assertFalse(session()->has(OwnerSession::UNLISTED_KEY));

        $owners->rememberUnlisted($this->channelId, [['id' => 'unl00000001']]);
        $owners->forget();
        $this->assertFalse(session()->has(OwnerSession::UNLISTED_KEY));
    }

    public function test_global_guard_skips_the_api_when_owner_reads_reach_half_of_the_headroom(): void
    {
        // google_daily 10,000 − write 5,000 − read 2,000 = 3,000 → guard at 1,500
        $this->assertSame(1500, QuotaPolicy::ownerReadGuard());
        app(QuotaLedger::class)->record(QuotaLedger::OWNER_READ, 1500);
        $this->fakeOwnerUploads([[]]);
        $this->actAsOwner($this->channelId)->fetchUnlisted()->assertStatus(503)
            ->assertExactJson(['message' => OwnerEditController::UNLISTED_FAILED]);
        $this->assertCount(0, Http::recorded());
    }

    public function test_guard_allows_reads_below_the_threshold(): void
    {
        app(QuotaLedger::class)->record(QuotaLedger::OWNER_READ, 1499);
        $this->fakeOwnerUploads([[]]);
        $this->actAsOwner($this->channelId)->fetchUnlisted()->assertOk();
    }

    public function test_api_failure_is_503_with_fixed_message_and_not_cached(): void
    {
        $this->fakeYouTubeError('playlistItems', 401, 'authError');
        $this->actAsOwner($this->channelId)->fetchUnlisted()->assertStatus(503)
            ->assertExactJson(['message' => OwnerEditController::UNLISTED_FAILED]);
        $this->assertFalse(session()->has(OwnerSession::UNLISTED_KEY));
    }

    public function test_rate_limited_per_owner_channel_with_japanese_json(): void
    {
        $this->fakeOwnerUploads([[]]);
        $this->fakeUploads('UU'.str_repeat('z', 22), [[]]);
        for ($i = 0; $i < 5; $i++) {
            $this->actAsOwner($this->channelId)->fetchUnlisted()->assertOk();
        }
        $this->actAsOwner($this->channelId)->fetchUnlisted()->assertStatus(429)
            ->assertExactJson(['message' => '限定公開の動画の読み込みが続いています。10 分ほど待ってから再度お試しください']);
        $this->actAsOwner('UC'.str_repeat('z', 22))->fetchUnlisted()->assertOk();
    }
}

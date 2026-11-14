<?php

namespace Tests\Feature;

use App\Http\Middleware\RequireOwnerSession;
use App\Models\PlaylistCreation;
use App\Models\PlaylistCreationItem;
use App\Models\QuotaReservation;
use App\Services\Creation\CreationProgress;
use App\Services\Creation\CreationRunner;
use App\Services\YouTube\QuotaLedger;
use App\Services\YouTube\YouTubeReader;
use App\Support\OwnerSession;
use App\Support\QuotaClock;
use App\Support\RemainingToday;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\CreatesProposals;
use Tests\Support\EnforcesCsrf;
use Tests\Support\FakesGoogle;
use Tests\TestCase;

/** Spec 7.3–7.5: the JSON endpoints of S6 (step, pause, resume, abort, retry, recreate) and the progress JSON. */
#[Group('mysql')]
class CreationEndpointsTest extends TestCase
{
    use CreatesProposals, EnforcesCsrf, FakesGoogle, RefreshDatabase;

    private const BUSY = '処理中の動画があります。数秒待ってからもう一度押してください';

    protected function setUp(): void
    {
        parent::setUp();
        // 2026-11-14 19:00 PST; the next reset is 2026-11-15 17:00 JST.
        $this->travelTo(CarbonImmutable::parse('2026-11-15 03:00:00'));
    }

    private function act(string $key, string $action, array $headers = ['Sec-Fetch-Site' => 'same-origin']): TestResponse
    {
        // withCredentials: send cookies set with withCookie() (the session id) on JSON requests too.
        return $this->withCredentials()->postJson("/p/$key/creation/$action", [], $headers);
    }

    /** @return array{0: string, 1: PlaylistCreation} */
    private function owned(int $items = 3, array $attrs = [], ?int $reserve = null): array
    {
        $key = $this->createProposal();
        $c = $this->makeCreation($items, $attrs, $reserve, $key);
        $this->actAsOwner($this->channelId);

        return [$key, $c];
    }

    private function item(PlaylistCreation $c, int $position): PlaylistCreationItem
    {
        return $c->items()->where('position', $position)->sole();
    }

    private function setItem(PlaylistCreation $c, int $position, array $attrs): void
    {
        $this->item($c, $position)->update($attrs);
    }

    private function useWrite(int $units, ?string $day = null): void
    {
        DB::table('quota_usage')->updateOrInsert(['day_pt' => $day ?? QuotaClock::todayPt(), 'bucket' => QuotaLedger::WRITE], ['units' => $units]);
    }

    private function itemInserts()
    {
        return Http::recorded(fn (Request $r) => $r->method() === 'POST' && str_starts_with($r->url(), YouTubeReader::BASE_URL.'playlistItems?'))
            ->map(fn ($p) => $p[0])->values();
    }

    // ---- step ----

    public function test_step_returns_progress_json(): void
    {
        [$key, $c] = $this->owned(3, reserve: 200);
        $this->fakePlaylistInsert('PLx');

        $res = $this->act($key, 'step')->assertOk()->assertJson([
            'status' => 'adding', 'pause_reason' => null, 'done' => 0, 'failed' => 0, 'skipped' => 0, 'total' => 3,
            'next_delay_ms' => 1000, 'playlist_url' => 'https://www.youtube.com/playlist?list=PLx', 'message' => null,
            'remaining_today' => '本日の残り: 再生リスト 1 つ＋動画あと 95 本まで作成できます',
            'items' => [
                ['position' => 0, 'status' => 'pending', 'error' => null],
                ['position' => 1, 'status' => 'pending', 'error' => null],
                ['position' => 2, 'status' => 'pending', 'error' => null],
            ],
        ]);
        $this->assertSame(
            ['status', 'pause_reason', 'done', 'failed', 'skipped', 'total', 'playlist_url', 'message', 'remaining_today', 'items', 'next_delay_ms'],
            array_keys($res->json()),
        );
    }

    public function test_step_message_prefers_runner_message(): void
    {
        [$key] = $this->owned(2, reserve: 150);
        $this->fakePlaylistInsert(status: 403, reason: 'forbidden');

        $this->act($key, 'step')->assertOk()->assertJson([
            'status' => 'completed', 'skipped' => 2, 'next_delay_ms' => 0, 'message' => CreationRunner::PLAYLIST_FAILED,
        ]);
    }

    public function test_google_401_pauses_forgets_login_and_returns_401(): void
    {
        [$key, $c] = $this->owned(3, ['status' => PlaylistCreation::ADDING, 'youtube_playlist_id' => 'PLx'], 150);
        $this->fakePlaylistItemInsert([[401, 'authError']]);

        $this->act($key, 'step')->assertStatus(401)->assertExactJson(['message' => RequireOwnerSession::NOTICE]);

        $this->assertFalse(session()->has(OwnerSession::OWNER_KEY));
        $c->refresh();
        $this->assertSame([PlaylistCreation::PAUSED, PlaylistCreation::PAUSE_AUTH], [$c->status, $c->pause_reason]);
        $this->assertSame(QuotaReservation::ACTIVE, QuotaReservation::sole()->status);
        $this->assertSame(PlaylistCreationItem::PENDING, $this->item($c, 0)->status);

        // The next call without a login is the same 401; the paused message tells the owner what to do after logging in.
        $this->act($key, 'step')->assertStatus(401);
        $this->assertSame(
            'ログインの有効期限が切れたため一時停止しました。もう一度ログインして「続きから」を押してください',
            CreationProgress::message($c->refresh(), app(QuotaLedger::class)->remainingToday()),
        );
    }

    public function test_expired_session_gets_401_json_and_creation_is_untouched(): void
    {
        $key = $this->createProposal();
        $c = $this->makeCreation(2, reserve: 150, key: $key);
        $this->actAsOwner($this->channelId, now()->toImmutable()->subSecond());

        foreach (['step', 'pause', 'resume', 'abort', 'retry', 'recreate'] as $action) {
            $this->act($key, $action)->assertStatus(401)->assertExactJson(['message' => RequireOwnerSession::NOTICE]);
        }

        $c->refresh();
        $this->assertSame([PlaylistCreation::PENDING, null], [$c->status, $c->pause_reason]);
        $this->assertSame(0, app(QuotaLedger::class)->used(QuotaLedger::WRITE));
        Http::assertNothingSent();
    }

    public function test_invalid_input_redirect_payload(): void
    {
        [$key] = $this->owned(2, reserve: 150);
        $this->fakePlaylistInsert(status: 400, reason: 'invalidValue');

        $this->act($key, 'step')->assertOk()->assertExactJson(['redirect' => 'edit', 'message' => CreationRunner::REJECTED_INPUT]);
        $this->assertSame(0, PlaylistCreation::count());
    }

    public function test_step_is_limited_to_2_per_second_per_session(): void
    {
        [$key] = $this->owned(2, ['status' => PlaylistCreation::PAUSED, 'pause_reason' => PlaylistCreation::PAUSE_USER], 150);
        $cookie = session()->getId();

        $this->withCookie(config('session.cookie'), $cookie)->act($key, 'step')->assertOk();
        $this->withCookie(config('session.cookie'), $cookie)->act($key, 'step')->assertOk();
        $this->withCookie(config('session.cookie'), $cookie)->act($key, 'step')->assertStatus(429)
            ->assertJson(['message' => 'アクセスが集中しています。しばらく待ってから再度お試しください']);

        $this->travel(1)->seconds();
        $this->withCookie(config('session.cookie'), $cookie)->act($key, 'step')->assertOk();
    }

    public function test_other_channel_gets_403_json_and_nothing_is_sent(): void
    {
        $key = $this->createProposal();
        $c = $this->makeCreation(2, reserve: 150, key: $key);
        $this->actAsOwner('UC'.str_repeat('z', 22));

        foreach (['step', 'pause', 'resume', 'abort', 'retry', 'recreate'] as $action) {
            $this->act($key, $action)->assertForbidden()->assertExactJson(['message' => RequireOwnerSession::MISMATCH]);
        }

        // The creation's own owner channel is checked too (spec 6.4), not only the proposal's channel.
        $c->update(['owner_channel_id' => 'UC'.str_repeat('y', 22)]);
        $this->actAsOwner($this->channelId);
        foreach (['step', 'pause', 'abort', 'recreate'] as $action) {
            $this->act($key, $action)->assertForbidden()->assertExactJson(['message' => RequireOwnerSession::MISMATCH]);
        }

        $this->assertSame(PlaylistCreation::PENDING, $c->refresh()->status);
        Http::assertNothingSent();
    }

    public function test_without_creation_is_404_json(): void
    {
        $key = $this->createProposal();
        $this->actAsOwner($this->channelId);

        foreach (['step', 'pause', 'resume', 'abort', 'retry', 'recreate'] as $action) {
            $this->act($key, $action)->assertNotFound()->assertJsonStructure(['message']);
        }
    }

    public function test_endpoints_are_post_csrf_and_same_origin_only(): void
    {
        [$key] = $this->owned(2, reserve: 150);

        foreach (['step', 'pause', 'resume', 'abort', 'retry', 'recreate'] as $action) {
            $this->getJson("/p/$key/creation/$action")->assertStatus(405);
            $this->act($key, $action, ['Sec-Fetch-Site' => 'cross-site'])->assertForbidden();
        }

        // Without Fetch Metadata the X-CSRF-TOKEN header is required (Sec-Fetch-Site: same-origin passes the framework's origin check).
        $this->enforceCsrf();
        $this->act($key, 'pause', ['X-Requested-With' => 'XMLHttpRequest'])->assertStatus(419);
        $this->act($key, 'pause', ['X-Requested-With' => 'XMLHttpRequest', 'X-CSRF-TOKEN' => 'wrong'])->assertStatus(419);
        $this->act($key, 'pause', ['X-Requested-With' => 'XMLHttpRequest', 'X-CSRF-TOKEN' => session()->token()])->assertOk();
        Http::assertNothingSent();
    }

    public function test_progress_never_contains_token(): void
    {
        [$key] = $this->owned(2, reserve: 150);
        $this->actAsOwner($this->channelId, token: 'ya29.secret-owner-token');
        $this->fakePlaylistInsert('PLx');

        foreach (['step', 'pause', 'resume', 'abort'] as $action) {
            $this->assertStringNotContainsString('ya29.secret-owner-token', $this->act($key, $action)->getContent());
        }
        $this->assertStringNotContainsString('ya29', (string) json_encode(DB::table('playlist_creations')->get()));
    }

    // ---- pause / resume ----

    public function test_pause_then_resume_continues_from_same_position(): void
    {
        [$key, $c] = $this->owned(3, ['status' => PlaylistCreation::ADDING, 'youtube_playlist_id' => 'PLx'], 150);
        $this->fakePlaylistItemInsert(['I0', 'I1']);

        $this->act($key, 'step')->assertOk()->assertJson(['done' => 1]);
        $this->act($key, 'pause')->assertOk()->assertJson([
            'status' => 'paused', 'pause_reason' => 'user', 'message' => '一時停止中です。「続きから」で再開できます',
        ]);
        $this->act($key, 'step')->assertOk()->assertJson(['status' => 'paused', 'next_delay_ms' => 0, 'done' => 1]);
        $this->assertCount(1, $this->itemInserts());

        $this->act($key, 'resume')->assertOk()->assertJson(['status' => 'adding', 'pause_reason' => null]);
        $this->act($key, 'step')->assertOk()->assertJson(['done' => 2]);

        $this->assertSame([0, 1], $this->itemInserts()->map(fn (Request $r) => $r->data()['snippet']['position'])->all());
        $this->assertSame(['PLx', 'PLx'], $this->itemInserts()->map(fn (Request $r) => $r->data()['snippet']['playlistId'])->all());
        // Pausing twice, or a completed creation, changes nothing.
        $this->act($key, 'pause')->assertOk();
        $this->act($key, 'pause')->assertOk()->assertJson(['status' => 'paused']);
    }

    public function test_resume_returns_to_the_state_before_the_pause(): void
    {
        [$key, $c] = $this->owned(2, ['status' => PlaylistCreation::PAUSED, 'pause_reason' => PlaylistCreation::PAUSE_AUTH], 150);
        $this->act($key, 'resume')->assertOk()->assertJson(['status' => 'pending']);

        $c->refresh()->update(['status' => PlaylistCreation::PAUSED, 'playlist_attempted_at' => now()->subMinute()]);
        $this->act($key, 'resume')->assertOk()->assertJson(['status' => 'creating_playlist']);

        // Resume only acts on a paused creation.
        $this->act($key, 'resume')->assertOk()->assertJson(['status' => 'creating_playlist']);
    }

    public function test_resume_after_day_change_rereserves_or_refuses_with_reset_time(): void
    {
        $yesterday = QuotaClock::todayPt();
        [$key, $c] = $this->owned(3, ['status' => PlaylistCreation::PAUSED, 'pause_reason' => PlaylistCreation::PAUSE_USER, 'youtube_playlist_id' => 'PLx'], 150);
        $this->setItem($c, 0, ['status' => PlaylistCreationItem::DONE, 'youtube_item_id' => 'I0']);
        $key2 = $this->createProposal();
        $c2 = $this->makeCreation(2, ['status' => PlaylistCreation::PAUSED, 'pause_reason' => PlaylistCreation::PAUSE_QUOTA_BUDGET], 150, $key2);

        // 2026-11-15 01:00 PST: a new PT day.
        $this->travelTo(CarbonImmutable::parse('2026-11-15 09:00:00'));
        $this->actAsOwner($this->channelId);
        $today = QuotaClock::todayPt();
        $this->assertNotSame($yesterday, $today);

        $this->act($key, 'resume')->assertOk()->assertJson(['status' => 'adding']);
        $todays = QuotaReservation::where('creation_id', $c->id)->where('day_pt', $today)->sole();
        $this->assertSame([100, 0, QuotaReservation::ACTIVE], [$todays->reserved_units, $todays->consumed_units, $todays->status]);
        $this->assertSame(100, app(CreationRunner::class)->remainingUnitsNeeded($c->refresh()));
        $this->assertSame(QuotaReservation::EXPIRED, QuotaReservation::where('creation_id', $c->id)->where('day_pt', $yesterday)->sole()->status);

        // Not enough for c2 (playlist + 2 = 150): refused, still paused, nothing reserved today.
        $this->useWrite((int) config('youtube.quota.write_limit') - 100 - 100);
        $res = $this->act($key2, 'resume')->assertStatus(409);
        $this->assertStringContainsString('次にリセットされるのは 日本時間 11月16日 17時 です', $res->json('message'));
        $c2->refresh();
        $this->assertSame([PlaylistCreation::PAUSED, PlaylistCreation::PAUSE_QUOTA_BUDGET], [$c2->status, $c2->pause_reason]);
        $this->assertFalse(QuotaReservation::where('creation_id', $c2->id)->where('day_pt', $today)->exists());
    }

    public function test_resume_never_resends_an_item_whose_result_is_unknown(): void
    {
        [$key, $c] = $this->owned(2, ['status' => PlaylistCreation::PAUSED, 'pause_reason' => PlaylistCreation::PAUSE_USER, 'youtube_playlist_id' => 'PLx'], 100);
        $this->setItem($c, 0, ['status' => PlaylistCreationItem::IN_FLIGHT, 'leased_at' => now()->subSeconds(61), 'attempts' => 1]);
        $this->fakePlaylistItemsList(['v0000000000' => 'I0']);
        $this->fakePlaylistItemInsert(['I1']);

        $this->act($key, 'resume')->assertOk()->assertJson(['status' => 'adding', 'items' => [['position' => 0, 'status' => 'in_flight']]]);
        $this->act($key, 'step')->assertOk()->assertJson(['done' => 1]);

        $this->assertSame([PlaylistCreationItem::DONE, 'I0'], [$this->item($c, 0)->status, $this->item($c, 0)->youtube_item_id]);
        $this->assertCount(0, $this->itemInserts());
    }

    // ---- abort ----

    public function test_abort_skips_pending_completes_and_releases(): void
    {
        [$key, $c] = $this->owned(3, ['status' => PlaylistCreation::ADDING, 'youtube_playlist_id' => 'PLx'], 150);
        $this->setItem($c, 0, ['status' => PlaylistCreationItem::DONE, 'youtube_item_id' => 'I0']);
        $this->setItem($c, 2, ['status' => PlaylistCreationItem::IN_FLIGHT, 'leased_at' => now()->subSeconds(61), 'attempts' => 1]);

        $this->act($key, 'abort')->assertOk()->assertJson([
            'status' => 'completed', 'done' => 1, 'failed' => 0, 'skipped' => 2,
            'message' => '作成が完了しました（追加 1 本・失敗 0 本・中止 2 本）',
            'items' => [
                ['position' => 0, 'status' => 'done', 'error' => null],
                ['position' => 1, 'status' => 'skipped', 'error' => null],
                ['position' => 2, 'status' => 'skipped', 'error' => '追加されたか確認できませんでした（YouTube で確認してください）'],
            ],
        ]);
        $c->refresh();
        $this->assertNotNull($c->completed_at);
        $this->assertSame([null, null], [$this->item($c, 1)->last_error, $this->item($c, 2)->leased_at]);
        $this->assertSame('unknown_result', $this->item($c, 2)->last_error);
        $this->assertSame(QuotaReservation::RELEASED, QuotaReservation::sole()->status);

        // Aborting again changes nothing; a creation without a playlist ends with "not created".
        $this->act($key, 'abort')->assertOk()->assertJson(['skipped' => 2]);
        [$key2] = $this->owned(2, reserve: 150);
        $this->act($key2, 'abort')->assertOk()->assertJson([
            'status' => 'completed', 'skipped' => 2, 'playlist_url' => null, 'message' => '再生リストは作成されませんでした',
        ]);
    }

    public function test_abort_with_fresh_lease_is_409_busy(): void
    {
        [$key, $c] = $this->owned(2, ['status' => PlaylistCreation::ADDING, 'youtube_playlist_id' => 'PLx'], 100);
        $this->setItem($c, 0, ['status' => PlaylistCreationItem::IN_FLIGHT, 'leased_at' => now()->subSeconds(5), 'attempts' => 1]);

        $this->act($key, 'abort')->assertStatus(409)->assertExactJson(['message' => self::BUSY]);
        $this->assertSame(PlaylistCreation::ADDING, $c->refresh()->status);
        $this->assertSame([PlaylistCreationItem::IN_FLIGHT, PlaylistCreationItem::PENDING], $c->items()->pluck('status')->all());
        $this->assertSame(QuotaReservation::ACTIVE, QuotaReservation::sole()->status);

        [$key2, $c2] = $this->owned(2, ['status' => PlaylistCreation::CREATING_PLAYLIST, 'playlist_attempted_at' => now(), 'playlist_leased_at' => now()->subSeconds(59)], 150);
        $this->act($key2, 'abort')->assertStatus(409)->assertExactJson(['message' => self::BUSY]);
        $this->assertSame(PlaylistCreation::CREATING_PLAYLIST, $c2->refresh()->status);
    }

    public function test_abort_while_a_playlist_may_exist_asks_the_owner_to_check(): void
    {
        $maybe = 'YouTube に作りかけの再生リストが残っている可能性があります。YouTube Studio で確認してください';
        foreach ([
            'needs_check' => ['status' => PlaylistCreation::NEEDS_CHECK, 'playlist_attempted_at' => now()->subMinutes(2), 'playlist_attempts' => 1],
            'creating_playlist' => ['status' => PlaylistCreation::CREATING_PLAYLIST, 'playlist_attempted_at' => now()->subMinutes(2), 'playlist_leased_at' => now()->subSeconds(61), 'playlist_attempts' => 1],
        ] as $case => $attrs) {
            [$key, $c] = $this->owned(2, $attrs, 150);
            $this->act($key, 'abort')->assertOk()->assertJson(['status' => 'completed', 'playlist_url' => null, 'message' => $maybe], $case);
            $this->assertNotNull($c->refresh()->playlist_attempted_at, $case);
        }

        // From pending nothing is outstanding (a failed attempt was answered or reconciled): not created.
        [$key, $c] = $this->owned(2, ['status' => PlaylistCreation::PENDING, 'playlist_attempted_at' => now()->subMinutes(2), 'playlist_attempts' => 1], 150);
        $this->act($key, 'abort')->assertOk()->assertJson(['message' => '再生リストは作成されませんでした']);
        $this->assertNull($c->refresh()->playlist_attempted_at);
    }

    public function test_resume_after_google_quota_waits_for_the_pt_reset(): void
    {
        [$key, $c] = $this->owned(2, ['status' => PlaylistCreation::ADDING, 'youtube_playlist_id' => 'PLx'], 100);
        $this->fakePlaylistItemInsert([[403, 'quotaExceeded'], 'I0']);
        $this->act($key, 'step')->assertOk()->assertJson(['status' => 'paused', 'pause_reason' => 'quota_exceeded']);

        // 2026-11-14 23:59:59 PST: the same PT day.
        $this->travelTo(CarbonImmutable::parse('2026-11-15 07:59:59'));
        $this->actAsOwner($this->channelId);
        $this->act($key, 'resume')->assertStatus(409)->assertExactJson([
            'message' => 'YouTube 側の本日の上限に達しているため、まだ再開できません。次にリセットされるのは 日本時間 11月15日 17時 です',
        ]);
        $this->assertSame([PlaylistCreation::PAUSED, PlaylistCreation::PAUSE_QUOTA_EXCEEDED], [$c->refresh()->status, $c->pause_reason]);

        // 2026-11-15 00:00:00 PST: reset; resumes (and re-reserves for the new day).
        $this->travelTo(CarbonImmutable::parse('2026-11-15 08:00:00'));
        $this->actAsOwner($this->channelId);
        $this->act($key, 'resume')->assertOk()->assertJson(['status' => 'adding']);
        $this->assertSame(100, QuotaReservation::where('creation_id', $c->id)->where('day_pt', QuotaClock::todayPt())->sole()->reserved_units);
        $this->assertNull($c->refresh()->paused_day_pt);
    }

    public function test_quota_pause_day_comes_from_its_own_column_not_updated_at(): void
    {
        // Paused on the previous PT day, but the row was written today (updated_at): resumes.
        [$key, $c] = $this->owned(2, ['status' => PlaylistCreation::PAUSED, 'pause_reason' => PlaylistCreation::PAUSE_QUOTA_EXCEEDED, 'youtube_playlist_id' => 'PLx', 'paused_day_pt' => '2026-11-13']);
        $this->act($key, 'resume')->assertOk()->assertJson(['status' => 'adding']);
        $this->assertNull($c->refresh()->paused_day_pt);

        // Paused today, the row last written on an earlier day: still refused until the PT reset.
        [$key, $c] = $this->owned(2, ['status' => PlaylistCreation::PAUSED, 'pause_reason' => PlaylistCreation::PAUSE_QUOTA_EXCEEDED, 'youtube_playlist_id' => 'PLx', 'paused_day_pt' => QuotaClock::todayPt()]);
        DB::table('playlist_creations')->where('id', $c->id)->update(['updated_at' => now()->subDays(2)]);
        $this->act($key, 'resume')->assertStatus(409);
        $this->assertSame(PlaylistCreation::PAUSED, $c->refresh()->status);

        // Abort clears it.
        $this->act($key, 'abort')->assertOk()->assertJson(['status' => 'completed']);
        $this->assertNull($c->refresh()->paused_day_pt);
    }

    public function test_shortage_wording_does_not_count_the_playlist_once_it_exists(): void
    {
        $done = ['status' => PlaylistCreation::COMPLETED, 'youtube_playlist_id' => 'PLx', 'completed_at' => now()];
        [$key, $c] = $this->owned(3, $done);
        $c->items()->update(['status' => PlaylistCreationItem::FAILED]);
        $this->useWrite((int) config('youtube.quota.write_limit') - 120); // free 120: two videos, no playlist needed

        $this->assertSame(
            '本日の残り: 再生リスト 1 つ＋動画あと 2 本まで作成できます。あと 1 本減らすか、リセット後に作成してください（次にリセットされるのは 日本時間 11月15日 17時 です）',
            $this->act($key, 'retry')->assertStatus(409)->json('message'),
        );
    }

    // ---- retry failed ----

    public function test_retry_failed_reserves_and_requeues_only_failed(): void
    {
        $done = ['status' => PlaylistCreation::COMPLETED, 'youtube_playlist_id' => 'PLx', 'completed_at' => now()];
        [$key, $c] = $this->owned(3, $done);
        $this->setItem($c, 0, ['status' => PlaylistCreationItem::DONE, 'youtube_item_id' => 'I0']);
        $this->setItem($c, 1, ['status' => PlaylistCreationItem::FAILED, 'attempts' => 6, 'last_error' => 'videoNotFound', 'leased_at' => null]);
        $this->setItem($c, 2, ['status' => PlaylistCreationItem::DONE, 'youtube_item_id' => 'I2']);

        // Not enough for one video: refused, nothing changes.
        $this->useWrite((int) config('youtube.quota.write_limit') - 40);
        $res = $this->act($key, 'retry')->assertStatus(409);
        $this->assertStringContainsString('次にリセットされるのは', $res->json('message'));
        $this->assertSame(PlaylistCreationItem::FAILED, $this->item($c, 1)->status);
        $this->assertSame(PlaylistCreation::COMPLETED, $c->refresh()->status);

        DB::table('quota_usage')->update(['units' => 0]);
        $this->act($key, 'retry')->assertOk()->assertJson(['status' => 'adding', 'failed' => 0, 'done' => 2]);

        $c->refresh();
        $this->assertSame([PlaylistCreation::ADDING, null], [$c->status, $c->completed_at]);
        $i = $this->item($c, 1);
        $this->assertSame([PlaylistCreationItem::PENDING, 0, null, null], [$i->status, $i->attempts, $i->last_error, $i->leased_at]);
        $this->assertSame([PlaylistCreationItem::DONE, PlaylistCreationItem::DONE], [$this->item($c, 0)->status, $this->item($c, 2)->status]);
        $r = QuotaReservation::sole();
        $this->assertSame([50, 0, QuotaReservation::ACTIVE], [$r->reserved_units, $r->consumed_units, $r->status]);

        // It goes back to its original position (1 done before it).
        $this->fakePlaylistItemInsert(['I1']);
        $this->act($key, 'step')->assertOk()->assertJson(['status' => 'completed', 'done' => 3]);
        $this->assertSame([1], $this->itemInserts()->map(fn (Request $r) => $r->data()['snippet']['position'])->all());
    }

    public function test_retry_does_nothing_without_failed_items_or_playlist(): void
    {
        [$key, $c] = $this->owned(2, ['status' => PlaylistCreation::COMPLETED, 'completed_at' => now()]);
        $this->setItem($c, 0, ['status' => PlaylistCreationItem::FAILED]);
        $this->act($key, 'retry')->assertOk()->assertJson(['status' => 'completed', 'failed' => 1]);
        $this->assertSame(0, QuotaReservation::count());

        [$key2] = $this->owned(2, ['status' => PlaylistCreation::ADDING, 'youtube_playlist_id' => 'PLx'], 100);
        $this->act($key2, 'retry')->assertOk()->assertJson(['status' => 'adding']);
    }

    // ---- recreate ----

    public function test_recreate_only_from_needs_check(): void
    {
        [$key, $c] = $this->owned(2, ['status' => PlaylistCreation::ADDING, 'youtube_playlist_id' => 'PLx'], 100);
        $this->act($key, 'recreate')->assertOk()->assertJson(['status' => 'adding']);
        $this->assertSame('PLx', $c->refresh()->youtube_playlist_id);

        // Ambiguous playlist (reconcile could not decide): back to pending, a fresh playlist insert next.
        [$key2, $c2] = $this->owned(3, [
            'status' => PlaylistCreation::NEEDS_CHECK, 'playlist_attempted_at' => now()->subMinutes(2),
            'playlist_leased_at' => null, 'playlist_attempts' => 2,
        ], 200);
        QuotaReservation::where('creation_id', $c2->id)->update(['consumed_units' => 100]);
        $this->assertSame(
            '再生リストが作成されたか確認できませんでした。YouTube Studio で「私の再生リスト」が作成されていないか確認し、見つからなければ「見つからなかったので作り直す」を押してください',
            CreationProgress::message($c2, app(QuotaLedger::class)->remainingToday()),
        );

        $this->act($key2, 'recreate')->assertOk()->assertJson(['status' => 'pending', 'message' => null]);
        $c2->refresh();
        $this->assertSame([PlaylistCreation::PENDING, null, null, 0], [$c2->status, $c2->playlist_attempted_at, $c2->playlist_leased_at, $c2->playlist_attempts]);
        $r = QuotaReservation::where('creation_id', $c2->id)->sole();
        $this->assertSame([300, 100], [$r->reserved_units, $r->consumed_units]); // unused 200 = playlist + 3

        $this->fakePlaylistInsert('PLnew');
        $this->act($key2, 'step')->assertOk()->assertJson(['status' => 'adding', 'playlist_url' => 'https://www.youtube.com/playlist?list=PLnew']);
    }

    public function test_recreate_after_playlist_level_stop_sends_every_item_to_the_new_playlist(): void
    {
        [$key, $c] = $this->owned(3, ['status' => PlaylistCreation::NEEDS_CHECK, 'youtube_playlist_id' => 'PLold', 'playlist_attempted_at' => now()->subHour(), 'playlist_attempts' => 1], 200);
        QuotaReservation::where('creation_id', $c->id)->update(['consumed_units' => 150]);
        $this->setItem($c, 0, ['status' => PlaylistCreationItem::DONE, 'youtube_item_id' => 'I0', 'attempts' => 1]);
        // Left in_flight by a truncated reconcile (playlist_too_long): its result in the old playlist is unknown.
        $this->setItem($c, 1, ['status' => PlaylistCreationItem::IN_FLIGHT, 'leased_at' => null, 'attempts' => 2, 'last_error' => 'transient']);
        $this->setItem($c, 2, ['status' => PlaylistCreationItem::PENDING, 'last_error' => 'playlistNotFound']);

        $this->act($key, 'recreate')->assertOk()->assertJson(['status' => 'pending', 'playlist_url' => null, 'done' => 0]);

        $c->refresh();
        $this->assertSame([null, null, 0], [$c->youtube_playlist_id, $c->playlist_attempted_at, $c->playlist_attempts]);
        foreach ($c->items as $i) {
            $this->assertSame([PlaylistCreationItem::PENDING, 0, null, null, null], [$i->status, $i->attempts, $i->last_error, $i->leased_at, $i->youtube_item_id]);
        }
        $this->assertSame(200, app(CreationRunner::class)->remainingUnitsNeeded($c));
        $r = QuotaReservation::sole();
        $this->assertSame(200, $r->reserved_units - $r->consumed_units);

        $this->fakePlaylistInsert('PLnew');
        $this->fakePlaylistItemInsert(['N0', 'N1', 'N2']);
        for ($n = 0; $n < 4; $n++) {
            $this->act($key, 'step')->assertOk();
        }
        $this->assertSame(['PLnew', 'PLnew', 'PLnew'], $this->itemInserts()->map(fn (Request $r) => $r->data()['snippet']['playlistId'])->all());
        $this->assertSame([0, 1, 2], $this->itemInserts()->map(fn (Request $r) => $r->data()['snippet']['position'])->all());
        $this->assertSame(PlaylistCreation::COMPLETED, $c->refresh()->status);
    }

    public function test_recreate_refuses_without_quota_or_while_a_send_is_in_progress(): void
    {
        [$key, $c] = $this->owned(3, ['status' => PlaylistCreation::NEEDS_CHECK, 'youtube_playlist_id' => 'PLold']);
        $this->setItem($c, 0, ['status' => PlaylistCreationItem::DONE, 'youtube_item_id' => 'I0']);
        $this->useWrite((int) config('youtube.quota.write_limit') - 150);

        $res = $this->act($key, 'recreate')->assertStatus(409);
        $this->assertSame(
            '本日の残り: 再生リスト 1 つ＋動画あと 2 本まで作成できます。あと 1 本減らすか、リセット後に作成してください（次にリセットされるのは 日本時間 11月15日 17時 です）',
            $res->json('message'),
        );
        $c->refresh();
        $this->assertSame([PlaylistCreation::NEEDS_CHECK, 'PLold'], [$c->status, $c->youtube_playlist_id]);
        $this->assertSame([PlaylistCreationItem::DONE, 'I0'], [$this->item($c, 0)->status, $this->item($c, 0)->youtube_item_id]);
        $this->assertSame(0, QuotaReservation::count());

        DB::table('quota_usage')->update(['units' => 0]);
        $this->setItem($c, 1, ['status' => PlaylistCreationItem::IN_FLIGHT, 'leased_at' => now()->subSeconds(3), 'attempts' => 1]);
        $this->act($key, 'recreate')->assertStatus(409)->assertExactJson(['message' => self::BUSY]);
        $this->assertSame(PlaylistCreation::NEEDS_CHECK, $c->refresh()->status);
        $this->assertSame(0, QuotaReservation::count());
    }

    // ---- progress texts ----

    public function test_messages_and_error_labels(): void
    {
        $r = new RemainingToday(3, CarbonImmutable::parse('2026-11-15 17:00:00', 'Asia/Tokyo'));
        $c = new PlaylistCreation(['status' => PlaylistCreation::PAUSED, 'pause_reason' => PlaylistCreation::PAUSE_QUOTA_EXCEEDED, 'title' => 't']);
        $this->assertSame('YouTube 側の本日の上限に達したため一時停止しました。リセット後に「続きから」を押してください（次にリセットされるのは 日本時間 11月15日 17時 です）', CreationProgress::message($c, $r));
        $c->pause_reason = PlaylistCreation::PAUSE_QUOTA_BUDGET;
        $this->assertSame('本日の作成枠が足りないため一時停止しました。本日の残り: 再生リスト 1 つ＋動画あと 3 本まで作成できます', CreationProgress::message($c, $r));
        $c->status = PlaylistCreation::ADDING;
        $this->assertNull(CreationProgress::message($c, $r));

        $this->assertSame('動画が見つかりません（削除または非公開）', CreationProgress::errorLabel('videoNotFound'));
        foreach (['forbidden', 'videoNotAvailable', 'invalidValue'] as $reason) {
            $this->assertSame('この動画は追加できません', CreationProgress::errorLabel($reason));
        }
        $this->assertSame('YouTube との通信に失敗しました', CreationProgress::errorLabel('transient'));
        $this->assertSame('追加されたか確認できませんでした（YouTube で確認してください）', CreationProgress::errorLabel('unknown_result'));
        $this->assertSame('追加できませんでした', CreationProgress::errorLabel('backendError'));
        $this->assertNull(CreationProgress::errorLabel(null));
    }

    // ---- final review fixes (race 1, 5) ----

    public function test_abort_locks_the_write_row_before_skipping_items(): void
    {
        [$key, $c] = $this->owned(3, ['status' => PlaylistCreation::ADDING, 'youtube_playlist_id' => 'PLx'], 150);
        $sql = [];
        DB::listen(function ($q) use (&$sql) {
            $sql[] = $q->sql;
        });

        $this->act($key, 'abort')->assertOk()->assertJson(['status' => 'completed', 'skipped' => 3]);

        // Lock order creation → write row → items (the items' range updates run under the write lock).
        $update = array_key_first(array_filter($sql, fn ($s) => str_starts_with($s, 'update "playlist_creation_items"')));
        $this->assertNotNull($update, implode("\n", $sql));
        $before = array_slice($sql, 0, $update, true);
        $creation = max(array_keys(array_filter($before, fn ($s) => str_starts_with($s, 'select') && str_contains($s, 'from "playlist_creations"'))));
        $write = max(array_keys(array_filter($before, fn ($s) => str_starts_with($s, 'select') && str_contains($s, 'from "quota_usage"'))) ?: [-1]);
        $this->assertGreaterThan($creation, $write, implode("\n", $sql));
        $this->assertSame(QuotaReservation::RELEASED, QuotaReservation::sole()->status);
    }

    public function test_quota_exceeded_pause_without_a_day_is_resumable(): void
    {
        [$key, $c] = $this->owned(2, ['status' => PlaylistCreation::PAUSED, 'pause_reason' => PlaylistCreation::PAUSE_QUOTA_EXCEEDED, 'youtube_playlist_id' => 'PLx', 'paused_day_pt' => null]);
        $this->act($key, 'resume')->assertOk()->assertJson(['status' => 'adding']);
        $this->assertSame([null, null], [$c->refresh()->pause_reason, $c->paused_day_pt]);
    }

    public function test_pause_resume_abort_and_recreate_share_a_per_session_limit(): void
    {
        // Final review (security M3): 30 operations per minute per session, shared by the four buttons.
        [$key] = $this->owned(2, ['status' => PlaylistCreation::PAUSED, 'pause_reason' => PlaylistCreation::PAUSE_USER], 150);
        $cookie = session()->getId();
        $act = fn (string $action) => $this->withCookie(config('session.cookie'), $cookie)->act($key, $action);

        for ($i = 0; $i < 30; $i++) {
            $act(['pause', 'recreate'][$i % 2])->assertOk();
        }
        foreach (['pause', 'resume', 'abort', 'recreate'] as $action) {
            $act($action)->assertStatus(429)->assertJson(['message' => 'アクセスが集中しています。しばらく待ってから再度お試しください']);
        }
        $this->assertSame(PlaylistCreation::PAUSED, PlaylistCreation::sole()->status);

        $this->travel(61)->seconds();
        $act('pause')->assertOk();
    }
}

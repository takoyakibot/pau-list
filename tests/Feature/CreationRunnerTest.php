<?php

namespace Tests\Feature;

use App\Models\PlaylistCreation;
use App\Models\PlaylistCreationItem;
use App\Models\QuotaReservation;
use App\Services\Creation\CreationRunner;
use App\Services\Creation\StepResult;
use App\Services\YouTube\QuotaLedger;
use App\Services\YouTube\YouTubeReader;
use App\Support\OwnerLogin;
use App\Support\QuotaClock;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\CreatesProposals;
use Tests\Support\FakesGoogle;
use Tests\TestCase;

/** Spec 7.3: one write per step, position, leases, reconciling uncertain results, reservations. */
#[Group('mysql')]
class CreationRunnerTest extends TestCase
{
    use CreatesProposals, FakesGoogle, RefreshDatabase;

    private OwnerLogin $owner;

    protected function setUp(): void
    {
        parent::setUp();
        // 2026-11-14 19:00 PST
        $this->travelTo(CarbonImmutable::parse('2026-11-15 03:00:00'));
        $this->owner = new OwnerLogin('ya29.tok', now()->toImmutable()->addHour(), $this->channelId, 'ch');
    }

    private function step(PlaylistCreation $c): StepResult
    {
        return app(CreationRunner::class)->step($c, $this->owner);
    }

    private function ledger(): QuotaLedger
    {
        return app(QuotaLedger::class);
    }

    private function resetHttp(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
    }

    /** A creation whose playlist exists (status adding), with today's reservation. */
    private function adding(int $items = 3, ?int $reserve = null, array $attrs = []): PlaylistCreation
    {
        return $this->makeCreation($items, array_merge(['status' => PlaylistCreation::ADDING, 'youtube_playlist_id' => 'PLx'], $attrs), $reserve ?? 50 * $items);
    }

    /** @return list<int> */
    private function sentPositions(): array
    {
        return $this->itemInserts()->map(fn (Request $r) => $r->data()['snippet']['position'])->all();
    }

    private function itemInserts()
    {
        return Http::recorded(fn (Request $r) => $r->method() === 'POST' && str_starts_with($r->url(), YouTubeReader::BASE_URL.'playlistItems?'))
            ->map(fn ($p) => $p[0])->values();
    }

    private function playlistInserts()
    {
        return Http::recorded(fn (Request $r) => $r->method() === 'POST' && str_starts_with($r->url(), YouTubeReader::BASE_URL.'playlists?'))
            ->map(fn ($p) => $p[0])->values();
    }

    private function item(PlaylistCreation $c, int $position): PlaylistCreationItem
    {
        return $c->items()->where('position', $position)->sole();
    }

    // ---- playlist ----

    public function test_first_step_creates_playlist_and_consumes_50(): void
    {
        $c = $this->makeCreation(3, ['title' => '名前', 'description' => '説明', 'privacy_status' => 'unlisted'], reserve: 200);
        $this->fakePlaylistInsert('PLx');

        $r = $this->step($c);
        $c->refresh();

        $this->assertSame([PlaylistCreation::ADDING, 'PLx', 1000], [$c->status, $c->youtube_playlist_id, $r->nextDelayMs]);
        $this->assertSame([1, null], [$c->playlist_attempts, $c->playlist_leased_at]);
        $this->assertEquals(now()->toImmutable(), $c->playlist_attempted_at);
        $this->assertSame(50, $this->ledger()->used(QuotaLedger::WRITE));
        $this->assertSame(50, QuotaReservation::sole()->consumed_units);
        $req = $this->playlistInserts()->sole();
        $this->assertSame('Bearer ya29.tok', $req->header('Authorization')[0]);
        $this->assertSame(['snippet' => ['title' => '名前', 'description' => '説明'], 'status' => ['privacyStatus' => 'unlisted']], $req->data());
        $this->assertSame([null, false, null], [$r->message, $r->authExpired, $r->redirect]);
    }

    public function test_playlist_uncertain_result_is_reconciled_not_resent(): void
    {
        foreach (['connection' => fn () => throw new ConnectionException('timeout'), '5xx' => fn () => $this->googleError(503, 'backendError'), 'malformed' => fn () => Http::response(['kind' => 'youtube#playlist'])] as $case => $responder) {
            $this->resetHttp();
            $c = $this->makeCreation(1, reserve: 100);
            $sent = 0;
            Http::fake(function (Request $r) use ($responder, &$sent) {
                if ($r->method() !== 'POST' || ! str_starts_with($r->url(), YouTubeReader::BASE_URL.'playlists?')) {
                    return null;
                }
                $sent++;

                return $responder();
            });

            $r = $this->step($c);
            $c->refresh();
            $this->assertSame([PlaylistCreation::CREATING_PLAYLIST, null, null, 2000], [$c->status, $c->playlist_leased_at, $c->youtube_playlist_id, $r->nextDelayMs], $case);

            // Next step: reconcile (playlists.list), adopt what was created, no second insert.
            $this->fakeMyPlaylists([['id' => 'PLmade', 'title' => $c->title, 'publishedAt' => now()->utc()->format('Y-m-d\TH:i:s\Z')]]);
            $this->step($c);
            $c->refresh();
            $this->assertSame([PlaylistCreation::ADDING, 'PLmade'], [$c->status, $c->youtube_playlist_id], $case);
            $this->assertSame(1, $sent, $case);
            $this->assertSame(50, QuotaReservation::where('creation_id', $c->id)->sole()->consumed_units, $case);
        }
    }

    public function test_ambiguous_playlist_is_adopted_or_reinserted(): void
    {
        $t = now()->toImmutable()->subMinutes(5);
        $iso = fn (CarbonImmutable $at) => $at->utc()->format('Y-m-d\TH:i:s\Z');
        $make = fn () => $this->makeCreation(2, ['status' => PlaylistCreation::CREATING_PLAYLIST, 'playlist_attempted_at' => $t, 'playlist_attempts' => 1], reserve: 100);

        // One playlist with the same title published since the attempt (minus 60 s) → adopted, nothing inserted.
        $c = $make();
        $this->fakeMyPlaylists([
            ['id' => 'PLnew', 'title' => $c->title, 'publishedAt' => $iso($t->subSeconds(30))],
            ['id' => 'PLold', 'title' => $c->title, 'publishedAt' => $iso($t->subHours(2))],
            ['id' => 'PLother', 'title' => '別の名前', 'publishedAt' => $iso($t->addSecond())],
        ]);
        $r = $this->step($c);
        $c->refresh();
        $this->assertSame([PlaylistCreation::ADDING, 'PLnew', 1000], [$c->status, $c->youtube_playlist_id, $r->nextDelayMs]);
        $this->assertCount(0, $this->playlistInserts());
        $this->assertSame(1, $this->ledger()->used(QuotaLedger::OWNER_READ));
        $this->assertSame(0, $this->ledger()->used(QuotaLedger::WRITE));

        // None and no more pages → pending → the next step inserts.
        $this->resetHttp();
        $c = $make();
        $this->fakeMyPlaylists([['id' => 'PLold', 'title' => $c->title, 'publishedAt' => $iso($t->subHours(2))]]);
        $this->fakePlaylistInsert('PLfresh');
        $this->step($c);
        $this->assertSame(PlaylistCreation::PENDING, $c->refresh()->status);
        $this->assertCount(0, $this->playlistInserts());
        $this->step($c);
        $c->refresh();
        $this->assertSame([PlaylistCreation::ADDING, 'PLfresh', 2], [$c->status, $c->youtube_playlist_id, $c->playlist_attempts]);
        $this->assertEquals($t, $c->playlist_attempted_at); // the first attempt's time is kept

        // Two candidates → needs_check.
        $this->resetHttp();
        $c = $make();
        $this->fakeMyPlaylists([['id' => 'PLa', 'title' => $c->title, 'publishedAt' => $iso($t)], ['id' => 'PLb', 'title' => $c->title, 'publishedAt' => $iso($t->addMinute())]]);
        $this->step($c);
        $this->assertSame([PlaylistCreation::NEEDS_CHECK, null], [$c->refresh()->status, $c->youtube_playlist_id]);

        // None on the first page but more pages → needs_check.
        $this->resetHttp();
        $c = $make();
        $this->fakeMyPlaylists([], hasMore: true);
        $this->step($c);
        $this->assertSame(PlaylistCreation::NEEDS_CHECK, $c->refresh()->status);
        $this->assertCount(0, $this->playlistInserts());
    }

    public function test_reconcile_read_failure_retries_and_401_pauses(): void
    {
        $c = $this->makeCreation(1, ['status' => PlaylistCreation::CREATING_PLAYLIST, 'playlist_attempted_at' => now(), 'playlist_attempts' => 2], reserve: 100);
        Http::fake(fn (Request $r) => $r->method() === 'GET' ? $this->googleError(500, 'backendError') : null);
        $r = $this->step($c);
        $c->refresh();
        $this->assertSame([PlaylistCreation::CREATING_PLAYLIST, null, 4000], [$c->status, $c->playlist_leased_at, $r->nextDelayMs]);

        $this->resetHttp();
        Http::fake(fn (Request $r) => $r->method() === 'GET' ? $this->googleError(401, 'authError') : null);
        $r = $this->step($c);
        $c->refresh();
        $this->assertTrue($r->authExpired);
        $this->assertSame([PlaylistCreation::PAUSED, PlaylistCreation::PAUSE_AUTH], [$c->status, $c->pause_reason]);
        $this->assertNotNull($c->playlist_attempted_at); // resume goes back to reconciling
        $this->assertCount(0, $this->playlistInserts());
    }

    public function test_fresh_playlist_lease_is_busy(): void
    {
        $c = $this->makeCreation(1, ['status' => PlaylistCreation::CREATING_PLAYLIST, 'playlist_attempted_at' => now(), 'playlist_leased_at' => now()->subSeconds(59), 'playlist_attempts' => 1], reserve: 100);
        $r = $this->step($c);
        $this->assertSame(1000, $r->nextDelayMs);
        $this->assertCount(0, Http::recorded());
        $this->assertSame(PlaylistCreation::CREATING_PLAYLIST, $c->refresh()->status);
    }

    public function test_playlist_transient_with_response_goes_back_to_pending_with_backoff(): void
    {
        $c = $this->makeCreation(1, reserve: 100);
        $this->fakePlaylistInsert(status: 429, reason: 'rateLimitExceeded');
        $r = $this->step($c);
        $c->refresh();
        $this->assertSame([PlaylistCreation::PENDING, null, 1, 2000], [$c->status, $c->playlist_leased_at, $c->playlist_attempts, $r->nextDelayMs]);
        $this->assertSame(50, $this->ledger()->used(QuotaLedger::WRITE));
    }

    public function test_playlist_attempts_limit_moves_to_needs_check_without_sending(): void
    {
        $c = $this->makeCreation(1, ['playlist_attempts' => CreationRunner::MAX_PLAYLIST_ATTEMPTS, 'playlist_attempted_at' => now()], reserve: 100);
        $r = $this->step($c);
        $this->assertSame([PlaylistCreation::NEEDS_CHECK, 0], [$c->refresh()->status, $r->nextDelayMs]);
        $this->assertCount(0, Http::recorded());
        $this->assertSame(0, $this->ledger()->used(QuotaLedger::WRITE));
        $this->assertSame(QuotaReservation::ACTIVE, QuotaReservation::sole()->status);
    }

    public function test_playlist_google_quota_and_401_pause_and_return_to_pending(): void
    {
        $c = $this->makeCreation(1, reserve: 100);
        $this->fakePlaylistInsert(status: 403, reason: 'quotaExceeded');
        $r = $this->step($c);
        $c->refresh();
        $this->assertSame([PlaylistCreation::PAUSED, PlaylistCreation::PAUSE_QUOTA_EXCEEDED, null, null, 0], [$c->status, $c->pause_reason, $c->playlist_attempted_at, $c->playlist_leased_at, $r->nextDelayMs]);
        $this->assertSame(QuotaReservation::ACTIVE, QuotaReservation::sole()->status);

        $this->resetHttp();
        $c = $this->makeCreation(1, reserve: 100);
        $this->fakePlaylistInsert(status: 401, reason: 'authError');
        $r = $this->step($c);
        $c->refresh();
        $this->assertTrue($r->authExpired);
        $this->assertSame([PlaylistCreation::PAUSED, PlaylistCreation::PAUSE_AUTH, null], [$c->status, $c->pause_reason, $c->playlist_attempted_at]);
    }

    public function test_invalid_playlist_input_deletes_creation_and_redirects_to_edit(): void
    {
        $c = $this->makeCreation(3, reserve: 200);
        $this->fakePlaylistInsert(status: 400, reason: 'invalidValue');

        $r = $this->step($c);

        $this->assertSame([0, CreationRunner::REJECTED_INPUT, 'edit', false], [$r->nextDelayMs, $r->message, $r->redirect, $r->authExpired]);
        $this->assertSame(0, PlaylistCreation::count());
        $this->assertSame(0, PlaylistCreationItem::count());
        $this->assertSame(0, QuotaReservation::count());
        $this->assertSame(50, $this->ledger()->used(QuotaLedger::WRITE));
        $this->assertSame((int) config('youtube.quota.write_limit') - 50, $this->ledger()->writeFreeUnits());
        $this->assertSame(
            'YouTube が再生リスト名または説明を受け付けませんでした。「<」「>」などの記号を除いて、編集画面から作成し直してください',
            CreationRunner::REJECTED_INPUT,
        );
    }

    public function test_permanent_playlist_failure_completes_with_all_skipped_and_releases(): void
    {
        $c = $this->makeCreation(2, reserve: 150);
        $this->fakePlaylistInsert(status: 403, reason: 'forbidden');

        $r = $this->step($c);
        $c->refresh();

        $this->assertSame([PlaylistCreation::COMPLETED, CreationRunner::PLAYLIST_FAILED, 0], [$c->status, $r->message, $r->nextDelayMs]);
        $this->assertNotNull($c->completed_at);
        $this->assertSame(['skipped', 'skipped'], $c->items()->pluck('status')->all());
        $this->assertSame(QuotaReservation::RELEASED, QuotaReservation::sole()->status);
        $this->assertSame('再生リストを作成できませんでした', CreationRunner::PLAYLIST_FAILED);
    }

    // ---- items ----

    public function test_items_are_added_in_order_with_position_and_then_completed_and_released(): void
    {
        Log::spy();
        $c = $this->makeCreation(3, reserve: 200);
        $this->fakePlaylistInsert('PLx');
        $this->fakePlaylistItemInsert(['I0', 'I1', 'I2']);

        $delays = [];
        for ($i = 0; $i < 4; $i++) {
            $delays[] = $this->step($c)->nextDelayMs;
        }
        $c->refresh();

        $this->assertSame([1000, 1000, 1000, 0], $delays); // 0: completed, nothing more to do
        $this->assertSame([0, 1, 2], $this->sentPositions());
        $this->assertSame(['v0000000000', 'v0000000001', 'v0000000002'], $this->itemInserts()->map(fn ($r) => $r->data()['snippet']['resourceId']['videoId'])->all());
        $this->assertSame(['PLx'], $this->itemInserts()->map(fn ($r) => $r->data()['snippet']['playlistId'])->unique()->values()->all());
        $this->assertSame(['done', 'done', 'done'], $c->items()->pluck('status')->all());
        $this->assertSame(['I0', 'I1', 'I2'], $c->items()->pluck('youtube_item_id')->all());
        $this->assertSame(PlaylistCreation::COMPLETED, $c->status);
        $this->assertNotNull($c->completed_at);
        $this->assertSame([QuotaReservation::RELEASED, 200], [QuotaReservation::sole()->status, QuotaReservation::sole()->consumed_units]);
        $this->assertSame(200, $this->ledger()->used(QuotaLedger::WRITE));
        $this->assertSame((int) config('youtube.quota.write_limit') - 200, $this->ledger()->writeFreeUnits());
        Log::shouldHaveReceived('info')->with('Creation completed', ['creation_id' => $c->id, 'done' => 3, 'failed' => 0, 'skipped' => 0])->once();

        // A completed creation does nothing more.
        $this->assertSame(0, $this->step($c)->nextDelayMs);
        $this->assertCount(4, Http::recorded());
    }

    public function test_retrying_a_failed_item_inserts_it_at_its_proposal_position(): void
    {
        $c = $this->adding(3);
        $this->fakePlaylistItemInsert(['I0', [404, 'videoNotFound'], 'I2', 'I1']);
        $this->step($c);
        $this->step($c);
        $this->step($c);
        $c->refresh();
        $this->assertSame(['done', 'failed', 'done'], $c->items()->pluck('status')->all());
        $this->assertSame('videoNotFound', $this->item($c, 1)->last_error);
        $this->assertSame(PlaylistCreation::COMPLETED, $c->status);

        // What "retry failed" (Task 7) does: failed → pending, adding, with 50 more reserved.
        $this->item($c, 1)->update(['status' => 'pending', 'attempts' => 0, 'last_error' => null]);
        $c->update(['status' => PlaylistCreation::ADDING, 'completed_at' => null]);
        app(QuotaLedger::class)->withWriteLock(fn ($lock) => $lock->ensure($c->id, 50));

        $this->step($c);
        $this->assertSame([0, 1, 1, 1], $this->sentPositions());
        $this->assertSame(['done', 'done', 'done'], $c->items()->pluck('status')->all());
        $this->assertSame(PlaylistCreation::COMPLETED, $c->refresh()->status);
    }

    public function test_position_for_counts_done_items_before_it(): void
    {
        $c = $this->adding(4);
        $this->item($c, 0)->update(['status' => 'done']);
        $this->item($c, 1)->update(['status' => 'failed']);
        $this->item($c, 2)->update(['status' => 'done']);
        $this->assertSame([0, 1, 1, 2], $c->items()->get()->map(fn ($i) => CreationRunner::positionFor($c, $i))->all());
    }

    public function test_remaining_units_needed(): void
    {
        $c = $this->makeCreation(3);
        $this->assertSame(200, app(CreationRunner::class)->remainingUnitsNeeded($c));
        $c->update(['youtube_playlist_id' => 'PLx']);
        $this->item($c, 0)->update(['status' => 'done']);
        $this->item($c, 1)->update(['status' => 'in_flight']);
        $this->assertSame(100, app(CreationRunner::class)->remainingUnitsNeeded($c));
    }

    public function test_transient_error_backs_off_and_fails_after_6_sends(): void
    {
        $c = $this->adding(2);
        $this->fakePlaylistItemInsert([[409, 'SERVICE_UNAVAILABLE'], 429, [403, 'rateLimitExceeded'], 409, 429, [409, 'SERVICE_UNAVAILABLE'], 'I1']);

        $delays = [];
        for ($i = 0; $i < 6; $i++) {
            $delays[] = $this->step($c)->nextDelayMs;
        }
        $item = $this->item($c, 0);

        $this->assertSame([2000, 4000, 8000, 16000, 32000, 1000], $delays);
        $this->assertSame(['failed', 6, 'SERVICE_UNAVAILABLE'], [$item->status, $item->attempts, $item->last_error]);
        $this->assertSame([0, 0, 0, 0, 0, 0], $this->sentPositions());
        $this->assertSame(300, $this->ledger()->used(QuotaLedger::WRITE)); // each send consumed 50, topped up from free

        // The next item goes on at position 0 (nothing done before it).
        $this->step($c);
        $this->assertSame(0, $this->sentPositions()[6]);
        $this->assertSame(PlaylistCreation::COMPLETED, $c->refresh()->status);
    }

    public function test_transient_without_reason_fails_as_transient(): void
    {
        $c = $this->adding(1, attrs: []);
        $this->item($c, 0)->update(['attempts' => CreationRunner::MAX_ATTEMPTS - 1]);
        $this->fakePlaylistItemInsert([429]);
        $this->step($c);
        $this->assertSame(['failed', 'transient'], [$this->item($c, 0)->status, $this->item($c, 0)->last_error]);
    }

    public function test_5xx_is_reconciled_before_each_resend_and_fails_after_6_sends(): void
    {
        $c = $this->adding(1);
        $this->fakePlaylistItemInsert(array_fill(0, 6, [503, 'backendError']));
        $this->fakePlaylistItemsList([]);

        $sendDelays = [];
        for ($i = 0; $i < 12; $i++) {
            $before = $this->itemInserts()->count();
            $r = $this->step($c);
            if ($this->itemInserts()->count() > $before) {
                $sendDelays[] = $r->nextDelayMs;
                $this->assertSame(['in_flight', null], [$this->item($c, 0)->status, $this->item($c, 0)->leased_at]);
            }
        }
        $item = $this->item($c, 0);

        $this->assertSame([2000, 4000, 8000, 16000, 32000, 1000], $sendDelays); // no backoff after the last send: reconcile, then fail
        $this->assertCount(6, $this->itemInserts());
        $lists = Http::recorded(fn (Request $r) => $r->method() === 'GET')->count();
        $this->assertSame(6, $lists); // one reconciliation after every uncertain send
        $this->assertSame(['failed', 6, 'backendError'], [$item->status, $item->attempts, $item->last_error]);
        $this->assertSame(PlaylistCreation::COMPLETED, $c->refresh()->status);
    }

    public function test_transient_retry_beyond_reservation_tops_up_or_pauses_quota_budget(): void
    {
        config(['youtube.quota.write_limit' => 1000]);
        $c = $this->adding(1, reserve: 50);
        $this->makeCreation(3, reserve: 950); // another creation holds the rest: free is 0
        $this->fakePlaylistItemInsert([409, 'I0']);

        $this->step($c); // consumes the reservation, 409 → pending
        $r = $this->step($c);
        $c->refresh();

        $this->assertCount(1, $this->itemInserts());
        $this->assertSame([PlaylistCreation::PAUSED, PlaylistCreation::PAUSE_QUOTA_BUDGET, 0], [$c->status, $c->pause_reason, $r->nextDelayMs]);
        $this->assertSame('本日はこれ以上作成できません。次にリセットされるのは 日本時間 11月15日 17時 です', $r->message);
        $this->assertSame('pending', $this->item($c, 0)->status);
        $this->assertSame(50, $this->ledger()->used(QuotaLedger::WRITE));
        $this->assertSame(QuotaReservation::ACTIVE, QuotaReservation::where('creation_id', $c->id)->sole()->status);
    }

    public function test_google_quota_pauses_and_keeps_reservation(): void
    {
        $c = $this->adding(2);
        $this->fakePlaylistItemInsert([[403, 'quotaExceeded']]);
        $r = $this->step($c);
        $c->refresh();
        $this->assertSame([PlaylistCreation::PAUSED, PlaylistCreation::PAUSE_QUOTA_EXCEEDED, 0, false], [$c->status, $c->pause_reason, $r->nextDelayMs, $r->authExpired]);
        $this->assertSame(['pending', null], [$this->item($c, 0)->status, $this->item($c, 0)->leased_at]);
        $this->assertSame([QuotaReservation::ACTIVE, 50], [QuotaReservation::sole()->status, QuotaReservation::sole()->consumed_units]);

        $this->step($c); // paused: nothing is sent
        $this->assertCount(1, $this->itemInserts());
    }

    public function test_google_401_pauses_auth_expired_and_flags(): void
    {
        $c = $this->adding(2);
        $this->fakePlaylistItemInsert([[401, 'authError']]);
        $r = $this->step($c);
        $c->refresh();
        $this->assertTrue($r->authExpired);
        $this->assertSame([PlaylistCreation::PAUSED, PlaylistCreation::PAUSE_AUTH], [$c->status, $c->pause_reason]);
        $this->assertSame(['pending', null], [$this->item($c, 0)->status, $this->item($c, 0)->leased_at]);
        $this->assertSame(QuotaReservation::ACTIVE, QuotaReservation::sole()->status);
    }

    public function test_item_permanent_errors_fail_only_that_item(): void
    {
        $c = $this->adding(4);
        $this->fakePlaylistItemInsert([[404, 'videoNotFound'], [400, 'invalidValue'], [418, null], 'I3']);
        // 403 forbidden on one video is item-level when the playlist itself is still readable (see I1 test).
        for ($i = 0; $i < 4; $i++) {
            $this->step($c);
        }
        $this->assertSame(['failed', 'failed', 'failed', 'done'], $c->items()->pluck('status')->all());
        $this->assertSame(['videoNotFound', 'invalidValue', 'unknown', null], $c->items()->pluck('last_error')->all());
        $this->assertSame([0, 0, 0, 0], $this->sentPositions());
        $this->assertSame(PlaylistCreation::COMPLETED, $c->refresh()->status);
    }

    public function test_playlist_level_item_errors_stop_the_creation_instead_of_failing_items(): void
    {
        foreach ([[404, 'playlistNotFound'], [403, 'playlistItemsNotAccessible'], [403, 'insufficientPermissions'], [403, 'playlistContainsMaximumNumberOfVideos'], [400, 'manualSortRequired']] as [$status, $reason]) {
            $this->resetHttp();
            $c = $this->adding(3);
            $this->fakePlaylistItemInsert([[$status, $reason]]);

            $r = $this->step($c);
            $c->refresh();

            $this->assertSame([PlaylistCreation::NEEDS_CHECK, 0], [$c->status, $r->nextDelayMs], $reason);
            $this->assertSame(['pending', 'pending', 'pending'], $c->items()->pluck('status')->all(), $reason);
            $this->assertSame($reason, $this->item($c, 0)->last_error);
            $this->assertSame(QuotaReservation::ACTIVE, QuotaReservation::where('creation_id', $c->id)->sole()->status);

            $this->step($c); // no more quota is spent
            $this->assertCount(1, $this->itemInserts(), $reason);
        }
    }

    public function test_forbidden_is_playlist_level_only_when_the_playlist_cannot_be_read(): void
    {
        // The playlist is gone: the probe answers 404 → needs_check.
        $c = $this->adding(2);
        $this->fakePlaylistItemInsert([[403, 'forbidden']]);
        Http::fake(fn (Request $r) => $r->method() === 'GET' ? $this->googleError(404, 'playlistNotFound') : null);
        $this->step($c);
        $this->assertSame(PlaylistCreation::NEEDS_CHECK, $c->refresh()->status);
        $this->assertSame('pending', $this->item($c, 0)->status);

        // The playlist is readable: only this video is refused.
        $this->resetHttp();
        $c = $this->adding(2);
        $this->fakePlaylistItemInsert([[403, 'forbidden'], 'I1']);
        $this->fakePlaylistItemsList([]);
        $this->step($c);
        $this->assertSame([PlaylistCreation::ADDING, 'failed', 'forbidden'], [$c->refresh()->status, $this->item($c, 0)->status, $this->item($c, 0)->last_error]);
        $this->step($c);
        $this->assertSame(PlaylistCreation::COMPLETED, $c->refresh()->status);
    }

    // ---- leases and reconciliation ----

    public function test_fresh_lease_makes_concurrent_step_busy_without_sending(): void
    {
        $c = $this->adding(3);
        $this->item($c, 0)->update(['status' => 'in_flight', 'leased_at' => now()->subSeconds(10), 'attempts' => 1]);

        $r = $this->step($c);

        $this->assertSame(1000, $r->nextDelayMs);
        $this->assertCount(0, Http::recorded());
        $this->assertSame(['in_flight', 'pending', 'pending'], $c->items()->pluck('status')->all());
        $this->assertSame(1, $this->item($c, 0)->attempts);
        $this->assertSame(0, QuotaReservation::sole()->consumed_units);
        $this->assertSame(0, $this->ledger()->used(QuotaLedger::WRITE));
        $this->assertSame(PlaylistCreation::ADDING, $c->refresh()->status);
    }

    public function test_stale_in_flight_is_reconciled_before_resend(): void
    {
        // Found in the playlist → done, nothing inserted.
        $c = $this->adding(3);
        $this->item($c, 0)->update(['status' => 'in_flight', 'leased_at' => now()->subSeconds(61), 'attempts' => 1]);
        $this->fakePlaylistItemsList(['v0000000000' => 'Ifound']);

        $r = $this->step($c);

        $this->assertSame(1000, $r->nextDelayMs);
        $this->assertSame(['done', 'Ifound', null, 1], [$this->item($c, 0)->status, $this->item($c, 0)->youtube_item_id, $this->item($c, 0)->leased_at, $this->item($c, 0)->attempts]);
        $this->assertCount(0, $this->itemInserts());
        $this->assertSame(1, $this->ledger()->used(QuotaLedger::OWNER_READ));
        $this->assertSame(0, $this->ledger()->used(QuotaLedger::WRITE));
        $list = Http::recorded()->sole()[0];
        parse_str((string) parse_url($list->url(), PHP_URL_QUERY), $q);
        $this->assertSame('PLx', $q['playlistId']);

        // Not there → pending (attempts kept), then the next step sends it.
        $this->resetHttp();
        $c = $this->adding(3);
        $this->item($c, 0)->update(['status' => 'in_flight', 'leased_at' => null, 'attempts' => 1]);
        $this->fakePlaylistItemsList(['vzzzzzzzzzz' => 'Iother']);
        $this->fakePlaylistItemInsert(['I0']);
        $this->step($c);
        $this->assertSame(['pending', 1], [$this->item($c, 0)->status, $this->item($c, 0)->attempts]);
        $this->assertCount(0, $this->itemInserts());
        $this->step($c);
        $this->assertSame(['done', 2], [$this->item($c, 0)->status, $this->item($c, 0)->attempts]);
        $this->assertSame([0], $this->sentPositions());
    }

    public function test_reconcile_reads_enough_pages_for_the_list(): void
    {
        $c = $this->adding(60);
        $this->item($c, 55)->update(['status' => 'in_flight', 'leased_at' => null, 'attempts' => 1]);
        $map = [];
        for ($i = 0; $i < 60; $i++) {
            $map[sprintf('v%010d', $i === 55 ? 9999 : $i)] = 'I'.$i;
        }
        $map[sprintf('v%010d', 55)] = 'I55';
        $this->fakePlaylistItemsList($map);
        $this->step($c);
        $this->assertSame(['done', 'I55'], [$this->item($c, 55)->status, $this->item($c, 55)->youtube_item_id]);
        $this->assertSame(2, $this->ledger()->used(QuotaLedger::OWNER_READ));
    }

    public function test_connection_error_on_item_is_reconciled_next_step(): void
    {
        $c = $this->adding(2);
        $sent = 0;
        $this->fakePlaylistItemInsert(function () use (&$sent) {
            $sent++;
            throw new ConnectionException('timed out');
        });
        $r = $this->step($c);
        $this->assertSame(2000, $r->nextDelayMs);
        $this->assertSame(['in_flight', null, 1], [$this->item($c, 0)->status, $this->item($c, 0)->leased_at, $this->item($c, 0)->attempts]);

        $this->fakePlaylistItemsList(['v0000000000' => 'Ilate']);
        $this->step($c);
        $this->assertSame(['done', 'Ilate'], [$this->item($c, 0)->status, $this->item($c, 0)->youtube_item_id]);
        $this->assertSame(1, $sent); // reconciled, not sent again
        $this->assertSame(50, $this->ledger()->used(QuotaLedger::WRITE));
    }

    public function test_reconcile_finding_the_playlist_gone_needs_check(): void
    {
        $c = $this->adding(2);
        $this->item($c, 0)->update(['status' => 'in_flight', 'leased_at' => null, 'attempts' => 1]);
        Http::fake(fn (Request $r) => $r->method() === 'GET' ? $this->googleError(404, 'playlistNotFound') : null);
        $this->step($c);
        $this->assertSame(PlaylistCreation::NEEDS_CHECK, $c->refresh()->status);
        $this->assertSame(['pending', null], [$this->item($c, 0)->status, $this->item($c, 0)->leased_at]); // no playlist, so not in it
        $this->assertCount(0, $this->itemInserts());
    }

    // ---- days, pauses ----

    public function test_day_rollover_rereserves_remaining_or_pauses(): void
    {
        $yesterday = QuotaClock::todayPt();
        $c = $this->adding(3, reserve: 150);
        QuotaReservation::where('creation_id', $c->id)->update(['consumed_units' => 50]);
        $this->item($c, 0)->update(['status' => 'done', 'youtube_item_id' => 'I0']);
        $this->fakePlaylistItemInsert(['I1']);

        $this->travelTo(CarbonImmutable::parse('2026-11-15 08:30:00')); // 2026-11-15 00:30 PST
        $this->assertNotSame($yesterday, QuotaClock::todayPt());
        $this->step($c);

        $today = QuotaReservation::where('creation_id', $c->id)->where('day_pt', QuotaClock::todayPt())->sole();
        $this->assertSame([100, 50, QuotaReservation::ACTIVE], [$today->reserved_units, $today->consumed_units, $today->status]);
        $this->assertSame(QuotaReservation::EXPIRED, QuotaReservation::where('creation_id', $c->id)->where('day_pt', $yesterday)->sole()->status);
        $this->assertSame([1], $this->sentPositions());
        $this->assertSame(50, $this->ledger()->used(QuotaLedger::WRITE));

        // Not enough free today → paused(quota_budget), nothing sent.
        $this->resetHttp();
        $this->travelTo(CarbonImmutable::parse('2026-11-15 03:00:00'));
        $c = $this->adding(2, reserve: 100);
        $this->travelTo(CarbonImmutable::parse('2026-11-15 08:30:00'));
        config(['youtube.quota.write_limit' => 1000]);
        $this->makeCreation(1, reserve: 950);
        $r = $this->step($c);
        $c->refresh();
        $this->assertSame([PlaylistCreation::PAUSED, PlaylistCreation::PAUSE_QUOTA_BUDGET, 0], [$c->status, $c->pause_reason, $r->nextDelayMs]);
        $this->assertStringContainsString('次にリセットされるのは 日本時間 11月16日 17時 です', $r->message);
        $this->assertCount(0, Http::recorded());
        $this->assertSame(0, QuotaReservation::where('creation_id', $c->id)->where('day_pt', QuotaClock::todayPt())->count());
    }

    public function test_pause_during_send_keeps_paused_status(): void
    {
        $c = $this->adding(2);
        $this->fakePlaylistItemInsert(function () use ($c) {
            PlaylistCreation::whereKey($c->id)->update(['status' => PlaylistCreation::PAUSED, 'pause_reason' => PlaylistCreation::PAUSE_USER]);

            return Http::response(['kind' => 'youtube#playlistItem', 'id' => 'I0']);
        });

        $this->step($c);
        $c->refresh();

        $this->assertSame(['done', 'I0'], [$this->item($c, 0)->status, $this->item($c, 0)->youtube_item_id]);
        $this->assertSame([PlaylistCreation::PAUSED, PlaylistCreation::PAUSE_USER], [$c->status, $c->pause_reason]);
    }

    public function test_pause_during_playlist_insert_keeps_paused_and_records_the_playlist(): void
    {
        $c = $this->makeCreation(1, reserve: 100);
        Http::fake(function (Request $r) use ($c) {
            PlaylistCreation::whereKey($c->id)->update(['status' => PlaylistCreation::PAUSED, 'pause_reason' => PlaylistCreation::PAUSE_USER]);

            return Http::response(['kind' => 'youtube#playlist', 'id' => 'PLx']);
        });
        $this->step($c);
        $c->refresh();
        $this->assertSame([PlaylistCreation::PAUSED, 'PLx', null], [$c->status, $c->youtube_playlist_id, $c->playlist_leased_at]);
    }

    public function test_paused_completed_and_needs_check_do_nothing(): void
    {
        foreach ([
            ['status' => PlaylistCreation::PAUSED, 'pause_reason' => PlaylistCreation::PAUSE_USER],
            ['status' => PlaylistCreation::COMPLETED, 'completed_at' => now()],
            ['status' => PlaylistCreation::NEEDS_CHECK],
        ] as $attrs) {
            $c = $this->makeCreation(2, $attrs, reserve: 150);
            $r = $this->step($c);
            $this->assertSame([0, null, false, null], [$r->nextDelayMs, $r->message, $r->authExpired, $r->redirect]);
            $this->assertSame($attrs['status'], $c->refresh()->status);
            $this->assertSame(0, QuotaReservation::where('creation_id', $c->id)->sole()->consumed_units);
        }
        $this->assertCount(0, Http::recorded());
        $this->assertSame(0, $this->ledger()->used(QuotaLedger::WRITE));
    }

    public function test_adding_without_pending_items_completes_and_releases(): void
    {
        $c = $this->adding(2);
        $c->items()->update(['status' => 'done']);
        $this->assertSame(0, $this->step($c)->nextDelayMs);
        $this->assertSame(PlaylistCreation::COMPLETED, $c->refresh()->status);
        $this->assertSame(QuotaReservation::RELEASED, QuotaReservation::sole()->status);
        $this->assertCount(0, Http::recorded());
    }

    public function test_no_token_or_text_is_logged_or_stored(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
            $logged[] = $e->message.' '.json_encode($e->context, JSON_UNESCAPED_UNICODE);
        });
        $c = $this->makeCreation(1, ['title' => '秘密の名前'], reserve: 100);
        $this->fakePlaylistInsert(status: 500, reason: 'backendError');
        $this->step($c);
        $this->fakeMyPlaylists([]);
        $this->step($c);
        $this->assertNotEmpty($logged);
        foreach ($logged as $line) {
            $this->assertStringNotContainsString('ya29', $line);
            $this->assertStringNotContainsString('秘密', $line);
        }
        foreach (['playlist_creations', 'playlist_creation_items', 'quota_reservations'] as $table) {
            $this->assertStringNotContainsString('ya29', json_encode(DB::table($table)->get()));
        }
    }
}

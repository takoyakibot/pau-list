<?php

namespace App\Services\Creation;

use App\Models\PlaylistCreation;
use App\Models\PlaylistCreationItem;
use App\Models\QuotaReservation;
use App\Services\YouTube\OwnerPlaylistsPage;
use App\Services\YouTube\QuotaLedger;
use App\Services\YouTube\WriteLock;
use App\Services\YouTube\YouTubeApiException;
use App\Services\YouTube\YouTubeError;
use App\Services\YouTube\YouTubeErrorKind;
use App\Services\YouTube\YouTubeOwnerClient;
use App\Support\OwnerLogin;
use App\Support\QuotaClock;
use App\Support\QuotaPolicy;
use App\Support\RemainingToday;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Spec 7.3: advances a creation by at most ONE YouTube write per step (the page calls step() repeatedly).
 *
 * Every step has three parts:
 *  1. decide (short transaction A): lock the creation row (then, if needed, the write row — always in that
 *     order, see QuotaLedger::withWriteLock), re-reserve after a PT date change, count the write against the
 *     reservation right before it is sent (WriteLock::consume) and take a 60-second lease on what is sent;
 *  2. send (no transaction, no lock): exactly one YouTube call;
 *  3. record (short transaction B): lock the creation row again and write the result.
 * A concurrent step (another tab, a double click) sees the fresh lease and only waits. A lease that is
 * empty (the result was unknown) or older than 60 seconds (the request died) is never sent again blindly:
 * it is reconciled first with a read (playlists.list / playlistItems.list, owner_read).
 * Tokens are only passed through to the client; nothing here stores or logs them (spec 7.7).
 */
final class CreationRunner
{
    public const NORMAL_DELAY_MS = 1000;

    public const LEASE_SECONDS = 60;

    /** Sends per item: the first one plus 5 retries (spec 5.7). */
    public const MAX_ATTEMPTS = 6;

    public const MAX_PLAYLIST_ATTEMPTS = 6;

    /**
     * After an unknown outcome, reconcile no sooner than this: a write that did happen may take a moment to show
     * in playlists.list / playlistItems.list (read-after-write lag), and reading too early would resend it.
     */
    public const RECONCILE_AFTER_SECONDS = 10;

    /** Item reconciliation reads the whole playlist up to this many pages (owner_read 1 each). */
    public const RECONCILE_MAX_PAGES = 20;

    /** Backoff 2, 4, 8, 16, 32 seconds (1000 × 2^attempts), never longer. */
    private const MAX_BACKOFF_MS = 32000;

    public const REJECTED_INPUT = 'YouTube が再生リスト名または説明を受け付けませんでした。「<」「>」などの記号を除いて、編集画面から作成し直してください';

    public const PLAYLIST_FAILED = '再生リストを作成できませんでした';

    /**
     * playlistItems.insert errors about the playlist itself, not the video: every later item would fail the
     * same way, so the creation stops (needs_check) instead of spending quota item by item (review I1).
     */
    private const PLAYLIST_LEVEL_REASONS = [
        'playlistNotFound',
        'playlistItemsNotAccessible',
        'insufficientPermissions',
        'playlistContainsMaximumNumberOfVideos',
        'manualSortRequired',
    ];

    public function __construct(
        private readonly YouTubeOwnerClient $youtube,
        private readonly QuotaLedger $ledger,
    ) {}

    public function step(PlaylistCreation $creation, OwnerLogin $owner): StepResult
    {
        $this->ledger->ensureWriteRow();

        $planned = DB::transaction(fn () => $this->decide($creation->id), 3);
        if ($planned instanceof StepResult) {
            return $planned;
        }

        $outcome = $this->send($planned, $owner);

        return DB::transaction(fn () => $this->record($planned, $outcome), 3);
    }

    /** Units still to write: the playlist (unless created) + 50 for each pending or in-flight item (spec 5.4). */
    public function remainingUnitsNeeded(PlaylistCreation $c): int
    {
        $items = $c->items()->whereIn('status', [PlaylistCreationItem::PENDING, PlaylistCreationItem::IN_FLIGHT])->count();

        return ($c->youtube_playlist_id === null ? QuotaPolicy::PLAYLIST_UNITS : 0) + QuotaPolicy::ITEM_UNITS * $items;
    }

    /** The snippet.position to send: the number of done items before this one (spec 7.3). */
    public static function positionFor(PlaylistCreation $c, PlaylistCreationItem $item): int
    {
        return $c->items()
            ->where('position', '<', $item->position)
            ->where('status', PlaylistCreationItem::DONE)
            ->count();
    }

    // ---- 1. decide ----

    private function decide(int $creationId): PlannedCall|StepResult
    {
        $c = PlaylistCreation::whereKey($creationId)->lockForUpdate()->first();
        if ($c === null || in_array($c->status, [PlaylistCreation::PAUSED, PlaylistCreation::COMPLETED, PlaylistCreation::NEEDS_CHECK], true)) {
            return new StepResult(0);
        }

        // After a PT date change yesterday's reservation no longer counts: reserve what is left for today.
        $short = $this->ledger->withWriteLock(function (WriteLock $lock) use ($c) {
            if ($lock->hasActiveToday($c->id) || $lock->ensure($c->id, $this->remainingUnitsNeeded($c))) {
                return null;
            }

            return self::remaining($lock);
        });
        if ($short !== null) {
            return $this->pauseForBudget($c, $short);
        }

        $now = CarbonImmutable::now()->startOfSecond();

        return match ($c->status) {
            PlaylistCreation::CREATING_PLAYLIST => $this->decideCreatingPlaylist($c, $now),
            PlaylistCreation::PENDING => $this->decidePending($c, $now),
            PlaylistCreation::ADDING => $this->decideAdding($c, $now),
            default => new StepResult(0),
        };
    }

    private function decideCreatingPlaylist(PlaylistCreation $c, CarbonImmutable $now): PlannedCall|StepResult
    {
        if (self::isFresh($c->playlist_leased_at, $now)) {
            return new StepResult(self::NORMAL_DELAY_MS); // being sent by another step
        }
        // The last insert's result is unknown (or its request died): look before sending again (spec 7.3).
        $c->update(['playlist_leased_at' => $now]);

        return new PlannedCall(PlannedCall::RECONCILE_PLAYLIST, $c, $now);
    }

    private function decidePending(PlaylistCreation $c, CarbonImmutable $now): PlannedCall|StepResult
    {
        if ($c->playlist_attempts >= self::MAX_PLAYLIST_ATTEMPTS) {
            $this->needsCheck($c, 'playlist_attempts');

            return new StepResult(0);
        }
        $short = $this->consume($c);
        if ($short !== null) {
            return $this->pauseForBudget($c, $short);
        }
        $c->update([
            'status' => PlaylistCreation::CREATING_PLAYLIST,
            'playlist_attempted_at' => $c->playlist_attempted_at ?? $now,
            'playlist_leased_at' => $now,
            'playlist_attempts' => $c->playlist_attempts + 1,
        ]);

        return new PlannedCall(PlannedCall::INSERT_PLAYLIST, $c, $now);
    }

    private function decideAdding(PlaylistCreation $c, CarbonImmutable $now): PlannedCall|StepResult
    {
        if ($c->youtube_playlist_id === null) {
            $this->needsCheck($c, 'missing_playlist_id'); // never expected: adding always has a playlist

            return new StepResult(0);
        }

        $inFlight = $c->items()->where('status', PlaylistCreationItem::IN_FLIGHT)->get();
        if ($inFlight->contains(fn (PlaylistCreationItem $i) => self::isFresh($i->leased_at, $now))) {
            return new StepResult(self::NORMAL_DELAY_MS);
        }
        $unknown = $inFlight->first();
        if ($unknown !== null) {
            $unknown->update(['leased_at' => $now]);

            return new PlannedCall(PlannedCall::RECONCILE_ITEM, $c, $now, $unknown);
        }

        $next = $c->items()->where('status', PlaylistCreationItem::PENDING)->first();
        if ($next === null) {
            $this->complete($c);

            return new StepResult(0);
        }
        $short = $this->consume($c);
        if ($short !== null) {
            return $this->pauseForBudget($c, $short);
        }
        $position = self::positionFor($c, $next);
        $next->update(['status' => PlaylistCreationItem::IN_FLIGHT, 'leased_at' => $now, 'attempts' => $next->attempts + 1]);

        return new PlannedCall(PlannedCall::INSERT_ITEM, $c, $now, $next, $position);
    }

    /** Count one write right before sending it; the remaining-today numbers when it cannot be covered. */
    private function consume(PlaylistCreation $c): ?RemainingToday
    {
        return $this->ledger->withWriteLock(fn (WriteLock $lock) => $lock->consume($c->id) ? null : self::remaining($lock));
    }

    // ---- 2. send ----

    private function send(PlannedCall $p, OwnerLogin $owner): CallOutcome
    {
        $c = $p->creation;
        $item = $p->item;

        try {
            return match ($p->kind) {
                PlannedCall::INSERT_PLAYLIST => $this->sendInsertPlaylist($c, $owner),
                PlannedCall::RECONCILE_PLAYLIST => CallOutcome::playlists($this->youtube->myPlaylists($owner->accessToken)),
                PlannedCall::INSERT_ITEM => $this->sendInsertItem($c, $item, (int) $p->position, $owner),
                PlannedCall::RECONCILE_ITEM => $this->sendReconcileItem($c, $owner),
            };
        } catch (YouTubeApiException $e) {
            return CallOutcome::failed($e->error);
        }
    }

    /** Reads the whole playlist (the owner may have added videos by hand), up to RECONCILE_MAX_PAGES pages. */
    private function sendReconcileItem(PlaylistCreation $c, OwnerLogin $owner): CallOutcome
    {
        $items = $this->youtube->playlistVideoItems($owner->accessToken, (string) $c->youtube_playlist_id, self::RECONCILE_MAX_PAGES, $truncated);

        return CallOutcome::items($items, (bool) $truncated);
    }

    private function sendInsertPlaylist(PlaylistCreation $c, OwnerLogin $owner): CallOutcome
    {
        try {
            return CallOutcome::created($this->youtube->insertPlaylist($owner->accessToken, $c->title, $c->description, $c->privacy_status));
        } catch (InvalidArgumentException) {
            // Refused before sending (validated at start, so not expected): handled like YouTube's 400.
            return CallOutcome::failed(new YouTubeError(YouTubeErrorKind::InvalidInput, null, 'invalidInput'));
        }
    }

    private function sendInsertItem(PlaylistCreation $c, PlaylistCreationItem $item, int $position, OwnerLogin $owner): CallOutcome
    {
        $playlistId = (string) $c->youtube_playlist_id;
        try {
            return CallOutcome::created($this->youtube->insertPlaylistItem($owner->accessToken, $playlistId, $item->video_id, $position));
        } catch (YouTubeApiException $e) {
            $error = $e->error;
            if (self::isPlaylistLevel($error)) {
                return CallOutcome::failed($error, playlistLevel: true);
            }
            if ($error->status === 403 && $error->kind === YouTubeErrorKind::ItemPermanent) {
                // A plain 403 can be about this video or about the playlist: read the playlist (owner_read 1).
                try {
                    $this->youtube->playlistVideoItems($owner->accessToken, $playlistId, 1);
                } catch (YouTubeApiException $probe) {
                    if ($probe->error->kind === YouTubeErrorKind::Auth) {
                        return CallOutcome::failed($probe->error);
                    }
                    if ($probe->error->kind === YouTubeErrorKind::ItemPermanent) {
                        return CallOutcome::failed($error, playlistLevel: true);
                    }
                }
            }

            return CallOutcome::failed($error);
        }
    }

    // ---- 3. record ----

    private function record(PlannedCall $p, CallOutcome $o): StepResult
    {
        $c = PlaylistCreation::whereKey($p->creation->id)->lockForUpdate()->first();
        if ($c === null) {
            return new StepResult(0);
        }

        return match ($p->kind) {
            PlannedCall::INSERT_PLAYLIST => $this->recordInsertPlaylist($c, $p, $o),
            PlannedCall::RECONCILE_PLAYLIST => $this->recordReconcilePlaylist($c, $p, $o),
            PlannedCall::INSERT_ITEM => $this->recordInsertItem($c, $p, $o),
            PlannedCall::RECONCILE_ITEM => $this->recordReconcileItem($c, $p, $o),
        };
    }

    private function recordInsertPlaylist(PlaylistCreation $c, PlannedCall $p, CallOutcome $o): StepResult
    {
        $ours = self::sameLease($c->playlist_leased_at, $p->leasedAt);
        if ($o->id !== null) {
            if ($c->youtube_playlist_id === null) {
                $c->youtube_playlist_id = $o->id;
                $this->progressTo($c, PlaylistCreation::ADDING, [PlaylistCreation::CREATING_PLAYLIST, PlaylistCreation::PENDING]);
            } elseif ($c->youtube_playlist_id !== $o->id) {
                // A slow insert succeeded after another playlist was adopted: YouTube now has an extra playlist.
                // Keep the adopted one, but never drop this silently (ids as short prefixes only).
                Log::warning('Creation late playlist insert', [
                    'creation_id' => $c->id,
                    'kept' => substr($c->youtube_playlist_id, 0, 8),
                    'late' => substr($o->id, 0, 8),
                ]);
            }
            if ($ours) {
                $c->playlist_leased_at = null;
            }
            $c->save();

            return new StepResult(self::NORMAL_DELAY_MS);
        }
        if (! $ours) {
            return new StepResult(self::NORMAL_DELAY_MS); // another step took over this attempt
        }

        $c->playlist_leased_at = null;
        $error = $o->error;
        if (self::isUncertain($error)) {
            // Created or not is unknown: stay in creating_playlist with a backdated lease, so a step reconciles
            // (never resends) RECONCILE_AFTER_SECONDS from now.
            $c->playlist_leased_at = self::reconcileLease();
            $c->save();

            return new StepResult(self::uncertainDelay($c->playlist_attempts));
        }

        switch ($error->kind) {
            case YouTubeErrorKind::Transient:
                $this->progressTo($c, PlaylistCreation::PENDING, [PlaylistCreation::CREATING_PLAYLIST]);
                $c->save();

                return new StepResult(self::backoff($c->playlist_attempts));
            case YouTubeErrorKind::GoogleQuota:
                $c->playlist_attempted_at = null; // nothing was created: resume goes back to pending
                $this->pause($c, PlaylistCreation::PAUSE_QUOTA_EXCEEDED);

                return new StepResult(0);
            case YouTubeErrorKind::Auth:
                $c->playlist_attempted_at = null;
                $this->pause($c, PlaylistCreation::PAUSE_AUTH);

                return new StepResult(0, authExpired: true);
            case YouTubeErrorKind::InvalidInput:
                // The title or description was refused: drop the creation and send the owner back to S5 (ruling).
                $this->deleteCreation($c, $error);

                return new StepResult(0, self::REJECTED_INPUT, redirect: 'edit');
            default:
                $c->items()->whereIn('status', [PlaylistCreationItem::PENDING, PlaylistCreationItem::IN_FLIGHT])
                    ->update(['status' => PlaylistCreationItem::SKIPPED, 'leased_at' => null]);
                Log::warning('Creation playlist failed', ['creation_id' => $c->id, 'status' => $error->status, 'reason' => $error->reason, 'kind' => $error->kind->value]);
                $this->complete($c);

                return new StepResult(0, self::PLAYLIST_FAILED);
        }
    }

    private function recordReconcilePlaylist(PlaylistCreation $c, PlannedCall $p, CallOutcome $o): StepResult
    {
        if (! self::sameLease($c->playlist_leased_at, $p->leasedAt)) {
            return new StepResult(self::NORMAL_DELAY_MS);
        }
        $c->playlist_leased_at = null;

        if ($o->error !== null) {
            if ($o->error->kind === YouTubeErrorKind::Auth) {
                $this->pause($c, PlaylistCreation::PAUSE_AUTH);

                return new StepResult(0, authExpired: true);
            }
            $c->save(); // reconcile again next time

            return new StepResult(self::backoff($c->playlist_attempts));
        }

        // Candidates: same title, published since the first attempt (minus 60 s), and not already another
        // creation's playlist (two owners' creations with the same title may run a minute apart).
        $since = ($c->playlist_attempted_at ?? $c->started_at)->subSeconds(self::LEASE_SECONDS);
        $candidates = array_values(array_filter(
            $o->playlists->items,
            fn ($pl) => $pl->title === $c->title && $pl->publishedAt !== null && $pl->publishedAt->greaterThanOrEqualTo($since),
        ));
        if ($candidates !== []) {
            $taken = PlaylistCreation::whereIn('youtube_playlist_id', array_map(fn ($pl) => $pl->id, $candidates))
                ->where('id', '!=', $c->id)->pluck('youtube_playlist_id')->all();
            $candidates = array_values(array_filter($candidates, fn ($pl) => ! in_array($pl->id, $taken, true)));
        }
        // Adopted only when exactly one also has the same description; any other candidate means the owner checks.
        $matches = array_values(array_filter($candidates, fn ($pl) => $pl->description === $c->description));

        if (count($candidates) === 1 && count($matches) === 1) {
            $c->youtube_playlist_id = $matches[0]->id;
            $this->progressTo($c, PlaylistCreation::ADDING, [PlaylistCreation::CREATING_PLAYLIST]);
            $c->save();

            return new StepResult(self::NORMAL_DELAY_MS);
        }
        if ($candidates === [] && ! $o->playlists->hasMore) {
            $this->progressTo($c, PlaylistCreation::PENDING, [PlaylistCreation::CREATING_PLAYLIST]); // not created: insert next
            $c->save();

            return new StepResult(self::NORMAL_DELAY_MS);
        }
        $this->needsCheck($c, $candidates === [] ? 'more_playlists' : 'ambiguous_candidates');

        return new StepResult(0);
    }

    private function recordInsertItem(PlaylistCreation $c, PlannedCall $p, CallOutcome $o): StepResult
    {
        $item = PlaylistCreationItem::find($p->item?->id);
        if ($item === null) {
            return new StepResult(0);
        }
        $ours = $item->status === PlaylistCreationItem::IN_FLIGHT && self::sameLease($item->leased_at, $p->leasedAt);

        if ($o->id !== null) {
            if ($item->status !== PlaylistCreationItem::DONE) {
                $item->update(['status' => PlaylistCreationItem::DONE, 'youtube_item_id' => $o->id, 'leased_at' => null, 'last_error' => null]);
            }

            return $this->afterItem($c);
        }
        if (! $ours) {
            return new StepResult(self::NORMAL_DELAY_MS);
        }

        $error = $o->error;
        $item->leased_at = null;

        if ($o->playlistLevel) {
            $item->status = PlaylistCreationItem::PENDING;
            $item->last_error = $error->reason ?? $error->kind->value;
            $item->save();
            $this->needsCheck($c, $item->last_error);

            return new StepResult(0);
        }
        if (self::isUncertain($error)) {
            // Added or not is unknown: keep in_flight with a backdated lease, so a step reconciles (before any
            // resend) RECONCILE_AFTER_SECONDS from now.
            $item->last_error = $error->reason ?? YouTubeErrorKind::Transient->value;
            $item->leased_at = self::reconcileLease();
            $item->save();

            return new StepResult(self::uncertainDelay($item->attempts >= self::MAX_ATTEMPTS ? 0 : $item->attempts));
        }

        switch ($error->kind) {
            case YouTubeErrorKind::Transient:
                $item->last_error = $error->reason ?? YouTubeErrorKind::Transient->value;
                if ($item->attempts >= self::MAX_ATTEMPTS) {
                    $item->status = PlaylistCreationItem::FAILED;
                    $item->save();

                    return $this->afterItem($c);
                }
                $item->status = PlaylistCreationItem::PENDING;
                $item->save();

                return new StepResult(self::backoff($item->attempts));
            case YouTubeErrorKind::GoogleQuota:
                $item->update(['status' => PlaylistCreationItem::PENDING]);
                $this->pause($c, PlaylistCreation::PAUSE_QUOTA_EXCEEDED);

                return new StepResult(0);
            case YouTubeErrorKind::Auth:
                $item->update(['status' => PlaylistCreationItem::PENDING]);
                $this->pause($c, PlaylistCreation::PAUSE_AUTH);

                return new StepResult(0, authExpired: true);
            default:
                $item->update(['status' => PlaylistCreationItem::FAILED, 'last_error' => $error->reason ?? $error->kind->value]);

                return $this->afterItem($c);
        }
    }

    private function recordReconcileItem(PlaylistCreation $c, PlannedCall $p, CallOutcome $o): StepResult
    {
        $item = PlaylistCreationItem::find($p->item?->id);
        if ($item === null || $item->status !== PlaylistCreationItem::IN_FLIGHT || ! self::sameLease($item->leased_at, $p->leasedAt)) {
            return new StepResult(self::NORMAL_DELAY_MS);
        }
        $item->leased_at = null;

        if ($o->error !== null) {
            $error = $o->error;
            if ($error->kind === YouTubeErrorKind::ItemPermanent) {
                // The playlist is gone or no longer ours: the video cannot be in it.
                $item->status = PlaylistCreationItem::PENDING;
                $item->save();
                $this->needsCheck($c, $error->reason ?? $error->kind->value);

                return new StepResult(0);
            }
            $item->save();
            if ($error->kind === YouTubeErrorKind::Auth) {
                $this->pause($c, PlaylistCreation::PAUSE_AUTH);

                return new StepResult(0, authExpired: true);
            }

            return new StepResult(self::backoff($item->attempts));
        }

        $itemId = $o->items[$item->video_id] ?? null;
        if ($itemId !== null) {
            $item->fill(['status' => PlaylistCreationItem::DONE, 'youtube_item_id' => $itemId, 'last_error' => null])->save();
        } elseif ($o->truncated) {
            // Not in the pages read, but the playlist is longer than that: resending could duplicate it.
            $item->save();
            $this->needsCheck($c, 'playlist_too_long');

            return new StepResult(0);
        } elseif ($item->attempts >= self::MAX_ATTEMPTS) {
            $item->fill(['status' => PlaylistCreationItem::FAILED, 'last_error' => $item->last_error ?? YouTubeErrorKind::Transient->value])->save();
        } else {
            $item->fill(['status' => PlaylistCreationItem::PENDING])->save(); // not added: send again (attempts kept)
        }

        return $this->afterItem($c);
    }

    /** After an item's result: completed when nothing is left to send (only while adding). */
    private function afterItem(PlaylistCreation $c): StepResult
    {
        if ($c->status === PlaylistCreation::ADDING
            && ! $c->items()->whereIn('status', [PlaylistCreationItem::PENDING, PlaylistCreationItem::IN_FLIGHT])->exists()) {
            $this->complete($c);

            return new StepResult(0);
        }

        return new StepResult(self::NORMAL_DELAY_MS);
    }

    // ---- state helpers (the creation row is locked by the caller) ----

    /** Move forward only from the expected states: a pause made during the send (user) is kept. */
    private function progressTo(PlaylistCreation $c, string $status, array $from): void
    {
        if (in_array($c->status, $from, true)) {
            $c->status = $status;
        }
    }

    private function pause(PlaylistCreation $c, string $reason): void
    {
        $c->fill(['status' => PlaylistCreation::PAUSED, 'pause_reason' => $reason])->save();
    }

    private function pauseForBudget(PlaylistCreation $c, RemainingToday $remaining): StepResult
    {
        $this->pause($c, PlaylistCreation::PAUSE_QUOTA_BUDGET);

        return new StepResult(0, $remaining->sentence());
    }

    private function needsCheck(PlaylistCreation $c, string $why): void
    {
        $c->fill(['status' => PlaylistCreation::NEEDS_CHECK, 'pause_reason' => null])->save();
        Log::warning('Creation needs check', ['creation_id' => $c->id, 'reason' => $why]);
    }

    /** completed + the unused reservation released (spec 5.4 step 3). */
    private function complete(PlaylistCreation $c): void
    {
        $c->fill(['status' => PlaylistCreation::COMPLETED, 'pause_reason' => null, 'completed_at' => now()])->save();
        $this->ledger->withWriteLock(fn (WriteLock $lock) => $lock->release($c->id));

        $counts = $c->items()->reorder()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        Log::info('Creation completed', [
            'creation_id' => $c->id,
            'done' => (int) ($counts[PlaylistCreationItem::DONE] ?? 0),
            'failed' => (int) ($counts[PlaylistCreationItem::FAILED] ?? 0),
            'skipped' => (int) ($counts[PlaylistCreationItem::SKIPPED] ?? 0),
        ]);
    }

    /** Explicitly removes the reservations, items and the creation (not only by FK cascade). */
    private function deleteCreation(PlaylistCreation $c, YouTubeError $error): void
    {
        $this->ledger->withWriteLock(function () use ($c) {
            QuotaReservation::where('creation_id', $c->id)->delete();
            PlaylistCreationItem::where('creation_id', $c->id)->delete();
            $c->delete();
        });
        Log::info('Creation input rejected by YouTube', ['creation_id' => $c->id, 'status' => $error->status, 'reason' => $error->reason]);
    }

    private static function remaining(WriteLock $lock): RemainingToday
    {
        return new RemainingToday(QuotaPolicy::itemsAffordable($lock->free()), QuotaClock::nextResetJst());
    }

    private static function isFresh(?CarbonImmutable $leasedAt, CarbonImmutable $now): bool
    {
        return $leasedAt !== null && $leasedAt->greaterThan($now->subSeconds(self::LEASE_SECONDS));
    }

    private static function sameLease(?CarbonImmutable $stored, CarbonImmutable $ours): bool
    {
        return $stored !== null && $stored->getTimestamp() === $ours->getTimestamp();
    }

    /**
     * The write may or may not have happened (review I3): no HTTP answer (connection/timeout), a 2xx without a
     * usable id, or a 5xx. Reconciled with a read before anything is sent again.
     */
    private static function isUncertain(YouTubeError $e): bool
    {
        return $e->status === null
            ? in_array($e->kind, [YouTubeErrorKind::Transient, YouTubeErrorKind::Unknown], true)
            : $e->status >= 500;
    }

    /** A lease that stops being fresh RECONCILE_AFTER_SECONDS from now. */
    private static function reconcileLease(): CarbonImmutable
    {
        return CarbonImmutable::now()->startOfSecond()->subSeconds(self::LEASE_SECONDS - self::RECONCILE_AFTER_SECONDS);
    }

    private static function uncertainDelay(int $attempts): int
    {
        return max(self::RECONCILE_AFTER_SECONDS * 1000, self::backoff($attempts));
    }

    /**
     * Errors about the playlist rather than the video (review I1), including a 400 about the requested position
     * (e.g. invalidPlaylistItemPosition): every later item would fail the same way.
     */
    private static function isPlaylistLevel(YouTubeError $e): bool
    {
        return in_array($e->reason, self::PLAYLIST_LEVEL_REASONS, true)
            || ($e->status === 400 && $e->reason !== null && stripos($e->reason, 'position') !== false);
    }

    private static function backoff(int $attempts): int
    {
        return min(self::MAX_BACKOFF_MS, self::NORMAL_DELAY_MS * 2 ** max(0, $attempts));
    }
}

/**
 * What decide() chose to send: the creation and item as they were when the lease was taken (read-only use).
 * Internal to CreationRunner.
 *
 * @internal
 */
final readonly class PlannedCall
{
    public const INSERT_PLAYLIST = 'insert_playlist';

    public const RECONCILE_PLAYLIST = 'reconcile_playlist';

    public const INSERT_ITEM = 'insert_item';

    public const RECONCILE_ITEM = 'reconcile_item';

    public function __construct(
        public string $kind,
        public PlaylistCreation $creation,
        public CarbonImmutable $leasedAt,
        public ?PlaylistCreationItem $item = null,
        public ?int $position = null,
    ) {}
}

/**
 * The result of the one YouTube call of a step. Internal to CreationRunner.
 *
 * @internal
 */
final readonly class CallOutcome
{
    /** @param array<string, string>|null $items */
    private function __construct(
        public ?string $id = null,
        public ?YouTubeError $error = null,
        public bool $playlistLevel = false,
        public ?OwnerPlaylistsPage $playlists = null,
        public ?array $items = null,
        public bool $truncated = false,
    ) {}

    public static function created(string $id): self
    {
        return new self(id: $id);
    }

    public static function failed(YouTubeError $error, bool $playlistLevel = false): self
    {
        return new self(error: $error, playlistLevel: $playlistLevel);
    }

    public static function playlists(OwnerPlaylistsPage $page): self
    {
        return new self(playlists: $page);
    }

    /** @param array<string, string> $items */
    public static function items(array $items, bool $truncated = false): self
    {
        return new self(items: $items, truncated: $truncated);
    }
}

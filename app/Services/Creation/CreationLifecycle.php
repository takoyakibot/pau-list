<?php

namespace App\Services\Creation;

use App\Models\PlaylistCreation;
use App\Models\PlaylistCreationItem;
use App\Services\YouTube\QuotaLedger;
use App\Services\YouTube\WriteLock;
use App\Support\QuotaClock;
use App\Support\QuotaPolicy;
use App\Support\RemainingToday;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The owner's operations on a creation from S6 (spec 7.4, 7.5): pause, resume, stop the rest, retry the
 * failed items, and recreate after needs_check. Each one locks the creation row first and, when quota is
 * involved, today's write row second (lock order of QuotaLedger::withWriteLock). An operation that does not
 * apply to the current state does nothing (the caller answers with the current progress).
 *
 * Results that are unknown (an item in_flight, a playlist insert whose answer was lost) are never turned into
 * a resend here: resume keeps them for CreationRunner to reconcile; abort and recreate refuse while a send is
 * still within its lease (CreationBusy), abort marks the stale ones skipped (unknown_result), and only recreate
 * (the owner's explicit choice of a NEW playlist) sends them again.
 */
final class CreationLifecycle
{
    public const UNKNOWN_RESULT = 'unknown_result';

    public function __construct(
        private readonly QuotaLedger $ledger,
        private readonly CreationRunner $runner,
    ) {}

    public function pause(PlaylistCreation $creation): void
    {
        $this->locked($creation, function (PlaylistCreation $c) {
            if (in_array($c->status, [PlaylistCreation::PENDING, PlaylistCreation::CREATING_PLAYLIST, PlaylistCreation::ADDING], true)) {
                $c->fill(['status' => PlaylistCreation::PAUSED, 'pause_reason' => PlaylistCreation::PAUSE_USER])->save();
            }
        });
    }

    /**
     * Back to where it stopped, after making sure today's reservation covers what is left (re-reserved after a
     * PT date change, spec 7.4). An item left in_flight stays in_flight: the next step reconciles it.
     *
     * After Google's quotaExceeded, not before the next PT reset: the pause's PT day is paused_day_pt (set by
     * CreationRunner when it paused). A missing day (never written by this version) is resumable: Google answers
     * quotaExceeded again if its limit is still reached, which only pauses again.
     *
     * @throws InsufficientWriteQuota
     * @throws GoogleQuotaNotReset
     */
    public function resume(PlaylistCreation $creation): void
    {
        $this->locked($creation, function (PlaylistCreation $c) {
            if ($c->status !== PlaylistCreation::PAUSED) {
                return;
            }
            if ($c->pause_reason === PlaylistCreation::PAUSE_QUOTA_EXCEEDED
                && $c->paused_day_pt !== null && (string) $c->paused_day_pt >= QuotaClock::todayPt()) {
                throw new GoogleQuotaNotReset(QuotaClock::nextResetJst());
            }
            $this->reserveOrFail($c, $this->runner->remainingUnitsNeeded($c), $this->openItems($c));
            $c->fill([
                'status' => $c->youtube_playlist_id !== null
                    ? PlaylistCreation::ADDING
                    : ($c->playlist_attempted_at !== null ? PlaylistCreation::CREATING_PLAYLIST : PlaylistCreation::PENDING),
                'pause_reason' => null,
                'paused_day_pt' => null,
            ])->save();
        });
    }

    /**
     * 「残りを中止」: unsent items → skipped, completed, reservation released (spec 7.4).
     *
     * @throws CreationBusy
     */
    public function abort(PlaylistCreation $creation): void
    {
        $this->locked($creation, function (PlaylistCreation $c) {
            if ($c->status === PlaylistCreation::COMPLETED) {
                return;
            }
            $this->assertNotBusy($c);
            if ($c->youtube_playlist_id === null && $c->status === PlaylistCreation::PENDING) {
                // Nothing outstanding: every earlier attempt was answered or reconciled as not created. Otherwise
                // (creating_playlist, needs_check, paused) playlist_attempted_at stays: a playlist may exist.
                $c->playlist_attempted_at = null;
            }

            // Lock order creation → write row → items: the items' range updates run under the write lock.
            $this->ledger->withWriteLock(function (WriteLock $lock) use ($c) {
                $c->items()->where('status', PlaylistCreationItem::PENDING)
                    ->update(['status' => PlaylistCreationItem::SKIPPED, 'leased_at' => null]);
                // Every in_flight lease is stale here (assertNotBusy): the result is unknown, never resent.
                $c->items()->where('status', PlaylistCreationItem::IN_FLIGHT)
                    ->update(['status' => PlaylistCreationItem::SKIPPED, 'leased_at' => null, 'last_error' => self::UNKNOWN_RESULT]);

                $c->fill(['status' => PlaylistCreation::COMPLETED, 'pause_reason' => null, 'paused_day_pt' => null, 'completed_at' => now()])->save();
                $lock->release($c->id);
            });
            Log::info('Creation aborted', ['creation_id' => $c->id]);
        });
    }

    /**
     * 「失敗分を再試行」 (spec 7.5): only for a completed creation with a playlist and failed items.
     *
     * @throws InsufficientWriteQuota
     */
    public function retryFailed(PlaylistCreation $creation): void
    {
        $this->locked($creation, function (PlaylistCreation $c) {
            if ($c->status !== PlaylistCreation::COMPLETED || $c->youtube_playlist_id === null) {
                return;
            }
            $failed = $c->items()->where('status', PlaylistCreationItem::FAILED)->count();
            if ($failed < 1) {
                return;
            }
            $this->reserveOrFail($c, QuotaPolicy::ITEM_UNITS * $failed, $failed);

            $c->items()->where('status', PlaylistCreationItem::FAILED)->update([
                'status' => PlaylistCreationItem::PENDING, 'attempts' => 0, 'last_error' => null, 'leased_at' => null,
            ]);
            $c->fill(['status' => PlaylistCreation::ADDING, 'pause_reason' => null, 'completed_at' => null])->save();
            Log::info('Creation retry failed', ['creation_id' => $c->id, 'items' => $failed]);
        });
    }

    /**
     * 「見つからなかったので作り直す」 after needs_check: the owner chose a NEW playlist. The old playlist id (if
     * any) is dropped and EVERY item (done ones included) goes back to pending, so the new playlist receives
     * all of them in order; the playlist + all items are reserved again (spec 7.3).
     *
     * @throws CreationBusy
     * @throws InsufficientWriteQuota
     */
    public function recreate(PlaylistCreation $creation): void
    {
        $this->locked($creation, function (PlaylistCreation $c) {
            if ($c->status !== PlaylistCreation::NEEDS_CHECK) {
                return;
            }
            // A send still in its lease would record its result (into the old playlist) after the reset.
            $this->assertNotBusy($c);
            $items = $c->items()->count();
            $this->reserveOrFail($c, QuotaPolicy::PLAYLIST_UNITS + QuotaPolicy::ITEM_UNITS * $items, $items);

            $c->items()->update([
                'status' => PlaylistCreationItem::PENDING,
                'attempts' => 0,
                'last_error' => null,
                'leased_at' => null,
                'youtube_item_id' => null,
            ]);
            $c->fill([
                'status' => PlaylistCreation::PENDING,
                'pause_reason' => null,
                'youtube_playlist_id' => null,
                'playlist_attempted_at' => null,
                'playlist_leased_at' => null,
                'playlist_attempts' => 0,
                'reconcile_failures' => 0,
                'completed_at' => null,
            ])->save();
            Log::info('Creation recreate', ['creation_id' => $c->id, 'items' => $items]);
        });
    }

    /** Runs $fn with the creation row locked (FOR UPDATE); a creation deleted meanwhile is skipped. */
    private function locked(PlaylistCreation $creation, callable $fn): void
    {
        $this->ledger->ensureWriteRow();
        DB::transaction(function () use ($creation, $fn) {
            $c = PlaylistCreation::whereKey($creation->id)->lockForUpdate()->first();
            if ($c !== null) {
                $fn($c);
            }
        }, 3);
    }

    /** Makes today's unused reservation at least $units (creation row already locked → write row second). */
    private function reserveOrFail(PlaylistCreation $c, int $units, int $items): void
    {
        $this->ledger->withWriteLock(function (WriteLock $lock) use ($c, $units, $items) {
            if (! $lock->ensure($c->id, $units)) {
                $free = $lock->free();
                Log::info('Write quota insufficient', ['items' => $items, 'free_units' => $free]);
                // With the playlist already created, every free 50 is one more video (the wording must not
                // subtract the playlist's 50); recreate always needs a new playlist (id cleared afterwards).
                $affordable = $c->youtube_playlist_id !== null && $c->status !== PlaylistCreation::NEEDS_CHECK
                    ? max(0, intdiv($free, QuotaPolicy::ITEM_UNITS))
                    : QuotaPolicy::itemsAffordable($free);
                throw new InsufficientWriteQuota(new RemainingToday($affordable, QuotaClock::nextResetJst()), $items);
            }
        });
    }

    private function openItems(PlaylistCreation $c): int
    {
        return $c->items()->whereIn('status', [PlaylistCreationItem::PENDING, PlaylistCreationItem::IN_FLIGHT])->count();
    }

    /** A playlist or item lease younger than CreationRunner::LEASE_SECONDS means a write is being sent now. */
    private function assertNotBusy(PlaylistCreation $c): void
    {
        $since = CarbonImmutable::now()->startOfSecond()->subSeconds(CreationRunner::LEASE_SECONDS);
        if (($c->playlist_leased_at !== null && $c->playlist_leased_at->greaterThan($since))
            || $c->items()->where('status', PlaylistCreationItem::IN_FLIGHT)->where('leased_at', '>', $since)->exists()) {
            throw new CreationBusy;
        }
    }
}

<?php

namespace App\Services\YouTube;

use App\Models\QuotaReservation;
use App\Support\QuotaPolicy;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Write-quota operations (spec 5.4: reserve → consume → release) that are only valid while today's
 * quota_usage(write) row is locked. Created by QuotaLedger::withWriteLock() only; never keep it after
 * the callback returns.
 *
 * @internal
 */
final class WriteLock
{
    public function __construct(private readonly string $dayPt) {}

    /** QUOTA_WRITE_LIMIT − today's sent writes − today's active reservations not yet consumed. */
    public function free(): int
    {
        $used = (int) DB::table('quota_usage')
            ->where('day_pt', $this->dayPt)->where('bucket', QuotaLedger::WRITE)
            ->value('units');

        return (int) config('youtube.quota.write_limit') - $used - QuotaReservation::unusedActiveOn($this->dayPt);
    }

    /** Today's active reserved − consumed for this creation (0 without one). */
    public function unused(int $creationId): int
    {
        $row = $this->today($creationId);

        return $row !== null && $row->status === QuotaReservation::ACTIVE
            ? $row->reserved_units - $row->consumed_units
            : 0;
    }

    public function hasActiveToday(int $creationId): bool
    {
        return $this->today($creationId)?->status === QuotaReservation::ACTIVE;
    }

    /**
     * Reserve $units more for this creation today; false (and nothing changed) when free is short.
     * Active rows of earlier days are expired; a released/expired row of today is reactivated.
     */
    public function reserve(int $creationId, int $units): bool
    {
        if ($units <= 0) {
            throw new InvalidArgumentException('Reserved units must be positive.');
        }
        if ($this->free() < $units) {
            return false;
        }
        $this->expireEarlierDays($creationId);

        $row = $this->today($creationId);
        if ($row === null) {
            QuotaReservation::create([
                'creation_id' => $creationId,
                'day_pt' => $this->dayPt,
                'reserved_units' => $units,
                'consumed_units' => 0,
                'status' => QuotaReservation::ACTIVE,
            ]);
        } elseif ($row->status === QuotaReservation::ACTIVE) {
            $row->update(['reserved_units' => $row->reserved_units + $units]);
        } else {
            $row->update([
                'status' => QuotaReservation::ACTIVE,
                'reserved_units' => $row->consumed_units + $units,
            ]);
        }

        return true;
    }

    /** Make today's unused reservation at least $needed (earlier days are expired either way). */
    public function ensure(int $creationId, int $needed): bool
    {
        $missing = max(0, $needed - $this->unused($creationId));
        if ($missing === 0) {
            $this->expireEarlierDays($creationId);

            return true;
        }

        return $this->reserve($creationId, $missing);
    }

    /**
     * Count one write (50) right before it is sent: from today's active reservation into quota_usage(write),
     * topping the reservation up by 50 from free if it ran short. False (nothing changed) without an active
     * reservation today (the day rolled over) or when free cannot cover the top-up.
     */
    public function consume(int $creationId): bool
    {
        if (! $this->hasActiveToday($creationId)) {
            return false;
        }
        if ($this->unused($creationId) < QuotaPolicy::ITEM_UNITS && ! $this->reserve($creationId, QuotaPolicy::ITEM_UNITS)) {
            return false;
        }

        QuotaReservation::where('creation_id', $creationId)
            ->where('day_pt', $this->dayPt)
            ->increment('consumed_units', QuotaPolicy::ITEM_UNITS);
        DB::table('quota_usage')
            ->where('day_pt', $this->dayPt)->where('bucket', QuotaLedger::WRITE)
            ->increment('units', QuotaPolicy::ITEM_UNITS);

        return true;
    }

    /** Give today's unused units back (released) and expire earlier days' active rows. */
    public function release(int $creationId): void
    {
        QuotaReservation::where('creation_id', $creationId)
            ->where('day_pt', $this->dayPt)
            ->where('status', QuotaReservation::ACTIVE)
            ->update(['status' => QuotaReservation::RELEASED]);
        $this->expireEarlierDays($creationId);
    }

    private function today(int $creationId): ?QuotaReservation
    {
        return QuotaReservation::where('creation_id', $creationId)->where('day_pt', $this->dayPt)->first();
    }

    private function expireEarlierDays(int $creationId): void
    {
        QuotaReservation::where('creation_id', $creationId)
            ->where('day_pt', '<', $this->dayPt)
            ->where('status', QuotaReservation::ACTIVE)
            ->update(['status' => QuotaReservation::EXPIRED]);
    }
}

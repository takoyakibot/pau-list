<?php

namespace App\Services\YouTube;

use App\Models\QuotaReservation;
use App\Support\QuotaClock;
use App\Support\QuotaPolicy;
use App\Support\RemainingToday;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class QuotaLedger
{
    public const READ = 'read';

    public const OWNER_READ = 'owner_read';

    public const WRITE = 'write';

    public function used(string $bucket, ?string $dayPt = null): int
    {
        return (int) DB::table('quota_usage')
            ->where('day_pt', $dayPt ?? QuotaClock::todayPt())
            ->where('bucket', $bucket)
            ->value('units');
    }

    public function record(string $bucket, int $units): void
    {
        $this->assertPositive($units);
        $this->locked($bucket, function (int $current, callable $add) use ($units) {
            $add($units);
        });
    }

    public function tryConsumeRead(int $units): bool
    {
        $this->assertPositive($units);
        $budget = (int) config('youtube.quota.read_budget');

        return $this->locked(self::READ, function (int $current, callable $add) use ($units, $budget) {
            if ($current + $units > $budget) {
                return false;
            }
            $add($units);

            return true;
        });
    }

    public function readRemaining(): int
    {
        return max(0, (int) config('youtube.quota.read_budget') - $this->used(self::READ));
    }

    /**
     * Spec 5.4 free units: limit − today's sent writes − today's active reservations not yet consumed.
     * Read without a lock (for display); decisions go through withWriteLock().
     */
    public function writeFreeUnits(): int
    {
        $day = QuotaClock::todayPt();

        return (int) config('youtube.quota.write_limit')
            - $this->used(self::WRITE, $day)
            - QuotaReservation::unusedActiveOn($day);
    }

    /**
     * Make sure today's write row exists. Call before (outside) opening your own transaction: inserting inside
     * it takes gap/insert-intention locks on InnoDB that can deadlock concurrent first requests of the day.
     */
    public function ensureWriteRow(): void
    {
        DB::table('quota_usage')->insertOrIgnore(['day_pt' => QuotaClock::todayPt(), 'bucket' => self::WRITE, 'units' => 0]);
    }

    /**
     * Run $fn(WriteLock) while holding today's quota_usage(write) row lock, so reservations and write
     * consumption of every creation are serialized (spec 5.4).
     *
     * @template T
     *
     * @param  callable(WriteLock): T  $fn
     * @return T
     */
    public function withWriteLock(callable $fn): mixed
    {
        if (DB::transactionLevel() === 0) {
            $this->ensureWriteRow();
        }

        return DB::transaction(function () use ($fn) {
            $day = QuotaClock::todayPt();
            $lockRow = fn () => DB::table('quota_usage')
                ->where('day_pt', $day)->where('bucket', self::WRITE)
                ->lockForUpdate()->first();

            if ($lockRow() === null) {
                // Called inside an outer transaction before the row existed: create it here and lock it.
                DB::table('quota_usage')->insertOrIgnore(['day_pt' => $day, 'bucket' => self::WRITE, 'units' => 0]);
                $lockRow();
            }

            return $fn(new WriteLock($day));
        }, 3);
    }

    public function remainingToday(): RemainingToday
    {
        return new RemainingToday(
            QuotaPolicy::itemsAffordable($this->writeFreeUnits()),
            QuotaClock::nextResetJst(),
        );
    }

    /**
     * Run $fn(currentUnits, add) while holding a row lock on today's bucket row.
     */
    private function locked(string $bucket, callable $fn): mixed
    {
        $day = QuotaClock::todayPt();

        // Make sure the row exists before (outside) the transaction: an insert inside it would take
        // gap/insert-intention locks on InnoDB that can deadlock concurrent first requests of the day.
        DB::table('quota_usage')->insertOrIgnore(['day_pt' => $day, 'bucket' => $bucket, 'units' => 0]);

        return DB::transaction(function () use ($day, $bucket, $fn) {
            $row = DB::table('quota_usage')
                ->where('day_pt', $day)->where('bucket', $bucket)
                ->lockForUpdate()->first();

            $add = fn (int $n) => DB::table('quota_usage')
                ->where('day_pt', $day)->where('bucket', $bucket)
                ->increment('units', $n);

            return $fn((int) $row->units, $add);
        }, 3);
    }

    private function assertPositive(int $units): void
    {
        if ($units <= 0) {
            throw new InvalidArgumentException('Quota units must be positive.');
        }
    }
}

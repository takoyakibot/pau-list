<?php

namespace App\Services\YouTube;

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

    public function writeFreeUnits(): int
    {
        // Phase 3 subtracts today's active reservations (reserved - consumed) here.
        return (int) config('youtube.quota.write_limit') - $this->used(self::WRITE);
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

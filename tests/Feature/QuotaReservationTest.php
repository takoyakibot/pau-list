<?php

namespace Tests\Feature;

use App\Models\QuotaReservation;
use App\Services\YouTube\QuotaLedger;
use App\Services\YouTube\WriteLock;
use App\Support\QuotaClock;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\CreatesProposals;
use Tests\TestCase;

/** Write-quota reservation → consumption → release (spec 5.4). Also runs on MySQL in CI. */
#[Group('mysql')]
class QuotaReservationTest extends TestCase
{
    use CreatesProposals, RefreshDatabase;

    private function ledger(): QuotaLedger
    {
        return app(QuotaLedger::class);
    }

    private function locked(callable $fn): mixed
    {
        return $this->ledger()->withWriteLock($fn);
    }

    private function row(int $creationId, ?string $day = null): ?QuotaReservation
    {
        return QuotaReservation::where('creation_id', $creationId)
            ->where('day_pt', $day ?? QuotaClock::todayPt())
            ->first();
    }

    public function test_unused_sum_is_signed_even_for_an_over_consumed_row(): void
    {
        // Never produced by WriteLock, but the free formula must not fail on it (MySQL unsigned subtraction).
        $a = $this->makeCreation(1);
        $b = $this->makeCreation(1);
        foreach ([[$a->id, 100, 150], [$b->id, 300, 100]] as [$id, $reserved, $consumed]) {
            QuotaReservation::create(['creation_id' => $id, 'day_pt' => QuotaClock::todayPt(), 'reserved_units' => $reserved, 'consumed_units' => $consumed, 'status' => QuotaReservation::ACTIVE]);
        }
        $this->assertSame(150, QuotaReservation::unusedActiveOn(QuotaClock::todayPt()));
    }

    public function test_reservations_reduce_remaining_for_others(): void
    {
        config(['youtube.quota.write_limit' => 5000]);
        $c = $this->makeCreation(49);
        $ok = $this->locked(fn (WriteLock $l) => $l->reserve($c->id, 2500));
        $this->assertTrue($ok);
        $this->assertSame(2500, $this->ledger()->writeFreeUnits());
        $this->assertSame(49, $this->ledger()->remainingToday()->items);   // floor((2500-50)/50)
    }

    public function test_reserve_refuses_without_change_when_short(): void
    {
        config(['youtube.quota.write_limit' => 5000]);
        $this->ledger()->record(QuotaLedger::WRITE, 4851);   // free 149
        $c = $this->makeCreation(2);
        $this->assertSame(149, $this->locked(fn (WriteLock $l) => $l->free()));
        $this->assertFalse($this->locked(fn (WriteLock $l) => $l->reserve($c->id, 150)));
        $this->assertSame(0, QuotaReservation::count());
        $this->assertSame(149, $this->ledger()->writeFreeUnits());
        $this->assertTrue($this->locked(fn (WriteLock $l) => $l->reserve($c->id, 149)));
        $this->assertSame(0, $this->ledger()->writeFreeUnits());
    }

    public function test_consume_moves_units_from_reservation_to_usage(): void
    {
        config(['youtube.quota.write_limit' => 5000]);
        $c = $this->makeCreation(2);
        $this->assertTrue($this->locked(fn (WriteLock $l) => $l->reserve($c->id, 150)));
        $this->assertSame(4850, $this->ledger()->writeFreeUnits());
        for ($i = 0; $i < 3; $i++) {
            $this->assertTrue($this->locked(fn (WriteLock $l) => $l->consume($c->id)));
        }
        $this->assertSame(150, $this->ledger()->used(QuotaLedger::WRITE));
        $row = $this->row($c->id);
        $this->assertSame(150, $row->consumed_units);
        $this->assertSame(150, $row->reserved_units);
        $this->assertSame(QuotaReservation::ACTIVE, $row->status);
        $this->assertSame(4850, $this->ledger()->writeFreeUnits());
        $this->assertSame(0, $this->locked(fn (WriteLock $l) => $l->unused($c->id)));
    }

    public function test_consume_tops_up_by_50_when_short_and_fails_without_free(): void
    {
        config(['youtube.quota.write_limit' => 200]);
        $c1 = $this->makeCreation(1);
        $c2 = $this->makeCreation(1);
        $this->assertTrue($this->locked(fn (WriteLock $l) => $l->reserve($c1->id, 100)));
        $this->assertTrue($this->locked(fn (WriteLock $l) => $l->reserve($c2->id, 100)));
        $this->assertTrue($this->locked(fn (WriteLock $l) => $l->consume($c1->id)));
        $this->assertTrue($this->locked(fn (WriteLock $l) => $l->consume($c1->id)));
        $this->assertFalse($this->locked(fn (WriteLock $l) => $l->consume($c1->id)));
        $this->assertSame(100, $this->ledger()->used(QuotaLedger::WRITE));
        $this->assertSame(100, $this->row($c1->id)->consumed_units);
        $this->assertSame(100, $this->row($c1->id)->reserved_units);

        // Once c2 lets go of its reservation, c1 tops up by 50 and sends.
        $this->locked(fn (WriteLock $l) => $l->release($c2->id));
        $this->assertTrue($this->locked(fn (WriteLock $l) => $l->consume($c1->id)));
        $this->assertSame(150, $this->row($c1->id)->reserved_units);
        $this->assertSame(150, $this->row($c1->id)->consumed_units);
        $this->assertSame(150, $this->ledger()->used(QuotaLedger::WRITE));
    }

    public function test_release_returns_unused_units(): void
    {
        config(['youtube.quota.write_limit' => 5000]);
        $c = $this->makeCreation(9);
        $this->assertTrue($this->locked(fn (WriteLock $l) => $l->reserve($c->id, 500)));
        $this->assertTrue($this->locked(fn (WriteLock $l) => $l->consume($c->id)));
        $this->assertSame(4500, $this->ledger()->writeFreeUnits());
        $this->locked(fn (WriteLock $l) => $l->release($c->id));
        $this->assertSame(4950, $this->ledger()->writeFreeUnits());
        $this->assertSame(QuotaReservation::RELEASED, $this->row($c->id)->status);
        $this->assertFalse($this->locked(fn (WriteLock $l) => $l->hasActiveToday($c->id)));
        $this->assertFalse($this->locked(fn (WriteLock $l) => $l->consume($c->id)));
    }

    public function test_old_day_reservation_is_not_counted_and_is_expired_on_reserve(): void
    {
        config(['youtube.quota.write_limit' => 5000]);
        $this->travelTo(CarbonImmutable::parse('2026-11-14 23:59', QuotaClock::TIMEZONE));
        $c = $this->makeCreation(5);
        $this->assertTrue($this->locked(fn (WriteLock $l) => $l->reserve($c->id, 2000)));
        $this->assertSame(3000, $this->ledger()->writeFreeUnits());

        $this->travel(2)->minutes();
        $this->assertSame('2026-11-15', QuotaClock::todayPt());
        $this->assertSame(5000, $this->ledger()->writeFreeUnits());
        $this->assertFalse($this->locked(fn (WriteLock $l) => $l->hasActiveToday($c->id)));
        $this->assertSame(0, $this->locked(fn (WriteLock $l) => $l->unused($c->id)));
        $this->assertFalse($this->locked(fn (WriteLock $l) => $l->consume($c->id)));
        $this->assertSame(0, $this->ledger()->used(QuotaLedger::WRITE));

        $this->assertTrue($this->locked(fn (WriteLock $l) => $l->ensure($c->id, 300)));
        $this->assertSame(QuotaReservation::EXPIRED, $this->row($c->id, '2026-11-14')->status);
        $today = $this->row($c->id, '2026-11-15');
        $this->assertSame(QuotaReservation::ACTIVE, $today->status);
        $this->assertSame(300, $today->reserved_units);
        $this->assertSame(0, $today->consumed_units);
        $this->assertSame(4700, $this->ledger()->writeFreeUnits());
    }

    public function test_ensure_tops_up_only_the_missing_part(): void
    {
        config(['youtube.quota.write_limit' => 5000]);
        $c = $this->makeCreation(5, [], 200);
        $this->assertTrue($this->locked(fn (WriteLock $l) => $l->ensure($c->id, 150)));
        $this->assertSame(200, $this->row($c->id)->reserved_units);
        $this->assertTrue($this->locked(fn (WriteLock $l) => $l->ensure($c->id, 300)));
        $this->assertSame(300, $this->row($c->id)->reserved_units);
        $this->assertSame(1, QuotaReservation::count());
    }

    public function test_reserve_reactivates_a_released_row_same_day(): void
    {
        config(['youtube.quota.write_limit' => 5000]);
        $c = $this->makeCreation(3);
        $this->assertTrue($this->locked(fn (WriteLock $l) => $l->reserve($c->id, 200)));
        $this->assertTrue($this->locked(fn (WriteLock $l) => $l->consume($c->id)));
        $this->locked(fn (WriteLock $l) => $l->release($c->id));
        $this->assertTrue($this->locked(fn (WriteLock $l) => $l->reserve($c->id, 100)));
        $row = $this->row($c->id);
        $this->assertSame(QuotaReservation::ACTIVE, $row->status);
        $this->assertSame(50, $row->consumed_units);
        $this->assertSame(150, $row->reserved_units);
        $this->assertTrue($this->locked(fn (WriteLock $l) => $l->hasActiveToday($c->id)));
        $this->assertSame(100, $this->locked(fn (WriteLock $l) => $l->unused($c->id)));
        $this->assertSame(1, QuotaReservation::count());
    }

    public function test_existing_ledger_behaviour_is_unchanged(): void
    {
        config(['youtube.quota.write_limit' => 5000]);
        $this->ledger()->record(QuotaLedger::WRITE, 300);
        $this->assertSame(4700, $this->ledger()->writeFreeUnits());
        $this->assertSame(93, $this->ledger()->remainingToday()->items);
    }

    public function test_with_write_lock_creates_the_write_row(): void
    {
        config(['youtube.quota.write_limit' => 5000]);
        $this->assertSame(0, DB::table('quota_usage')->count());
        $this->assertSame(5000, $this->locked(fn (WriteLock $l) => $l->free()));
        $this->assertSame(1, DB::table('quota_usage')->where('bucket', QuotaLedger::WRITE)->where('day_pt', QuotaClock::todayPt())->count());
    }
}

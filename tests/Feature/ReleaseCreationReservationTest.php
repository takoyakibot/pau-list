<?php

namespace Tests\Feature;

use App\Models\PlaylistCreation;
use App\Models\QuotaReservation;
use App\Services\YouTube\QuotaLedger;
use App\Support\QuotaClock;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesProposals;
use Tests\TestCase;

/**
 * The operator's fallback for a reservation held by an abandoned creation (final review, security I1; user
 * decision Y15 keeps reservations for the day, so nothing is released automatically).
 */
class ReleaseCreationReservationTest extends TestCase
{
    use CreatesProposals, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-15 03:00:00')); // 2026-11-14 PT
    }

    public function test_releases_active_reservations_and_pauses_a_running_creation(): void
    {
        $c = $this->makeCreation(3, ['status' => PlaylistCreation::ADDING, 'youtube_playlist_id' => 'PLx'], reserve: 150);
        QuotaReservation::where('creation_id', $c->id)->update(['consumed_units' => 50]);
        QuotaReservation::create(['creation_id' => $c->id, 'day_pt' => '2026-11-13', 'reserved_units' => 200, 'consumed_units' => 0, 'status' => QuotaReservation::ACTIVE]);
        $other = $this->makeCreation(1, reserve: 100);
        $freeBefore = app(QuotaLedger::class)->writeFreeUnits();

        $this->artisan('app:release-creation-reservation', ['creationId' => $c->id])
            ->expectsOutput('reservations released: 2')
            ->expectsOutput('paused: 1')
            ->assertSuccessful();

        $c->refresh();
        $this->assertSame([PlaylistCreation::PAUSED, PlaylistCreation::PAUSE_USER], [$c->status, $c->pause_reason]);
        $this->assertSame(QuotaReservation::RELEASED, QuotaReservation::where('creation_id', $c->id)->where('day_pt', QuotaClock::todayPt())->sole()->status);
        $this->assertSame(QuotaReservation::EXPIRED, QuotaReservation::where('creation_id', $c->id)->where('day_pt', '2026-11-13')->sole()->status);
        $this->assertSame(QuotaReservation::ACTIVE, QuotaReservation::where('creation_id', $other->id)->sole()->status);
        $this->assertSame($freeBefore + 100, app(QuotaLedger::class)->writeFreeUnits()); // the unused 150 − 50
        // Nothing of the creation's content is printed or logged; the counts are all.
        $this->assertSame(0, DB::table('quota_usage')->where('bucket', QuotaLedger::WRITE)->value('units'));
    }

    public function test_a_paused_or_completed_creation_keeps_its_status(): void
    {
        foreach ([
            [PlaylistCreation::PAUSED, PlaylistCreation::PAUSE_AUTH],
            [PlaylistCreation::NEEDS_CHECK, null],
            [PlaylistCreation::COMPLETED, null],
        ] as [$status, $reason]) {
            $c = $this->makeCreation(1, ['status' => $status, 'pause_reason' => $reason], reserve: 100);

            $this->artisan('app:release-creation-reservation', ['creationId' => $c->id])
                ->expectsOutput('reservations released: 1')
                ->expectsOutput('paused: 0')
                ->assertSuccessful();

            $c->refresh();
            $this->assertSame([$status, $reason], [$c->status, $c->pause_reason]);
            $this->assertSame(QuotaReservation::RELEASED, QuotaReservation::where('creation_id', $c->id)->sole()->status);
        }
    }

    public function test_unknown_or_malformed_id_fails_without_changes(): void
    {
        $c = $this->makeCreation(1, reserve: 100);

        $this->artisan('app:release-creation-reservation', ['creationId' => '999999'])
            ->expectsOutput('creation not found')
            ->assertFailed();
        $this->artisan('app:release-creation-reservation', ['creationId' => '1 or 1=1'])
            ->expectsOutput('creation not found')
            ->assertFailed();

        $this->assertSame(QuotaReservation::ACTIVE, QuotaReservation::where('creation_id', $c->id)->sole()->status);
        $this->assertSame(PlaylistCreation::PENDING, $c->refresh()->status);
    }
}

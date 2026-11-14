<?php

namespace Tests\Feature;

use App\Services\YouTube\QuotaLedger;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuotaLedgerTest extends TestCase
{
    use RefreshDatabase;

    public function test_record_accumulates_per_pt_day_and_bucket(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-14 12:00', 'Asia/Tokyo'));
        $ledger = app(QuotaLedger::class);
        $ledger->record(QuotaLedger::WRITE, 50);
        $ledger->record(QuotaLedger::WRITE, 50);
        $ledger->record(QuotaLedger::OWNER_READ, 1);
        $this->assertSame(100, $ledger->used(QuotaLedger::WRITE));
        $this->assertSame(1, $ledger->used(QuotaLedger::OWNER_READ));
        $this->assertSame(0, $ledger->used(QuotaLedger::READ));
    }

    public function test_try_consume_read_stops_at_budget(): void
    {
        config(['youtube.quota.read_budget' => 3]);
        $ledger = app(QuotaLedger::class);
        $this->assertTrue($ledger->tryConsumeRead(2));
        $this->assertFalse($ledger->tryConsumeRead(2));
        $this->assertTrue($ledger->tryConsumeRead(1));
        $this->assertSame(3, $ledger->used(QuotaLedger::READ));
        $this->assertSame(0, $ledger->readRemaining());
    }

    public function test_read_budget_resets_at_pacific_midnight(): void
    {
        config(['youtube.quota.read_budget' => 1]);
        $ledger = app(QuotaLedger::class);
        $this->travelTo(CarbonImmutable::parse('2026-11-14 16:30', 'Asia/Tokyo'));
        $this->assertTrue($ledger->tryConsumeRead(1));
        $this->travelTo(CarbonImmutable::parse('2026-11-15 00:30', 'Asia/Tokyo'));
        $this->assertTrue($ledger->tryConsumeRead(1));
        $this->travelTo(CarbonImmutable::parse('2026-11-15 16:59', 'Asia/Tokyo'));
        $this->assertFalse($ledger->tryConsumeRead(1));
        $this->travelTo(CarbonImmutable::parse('2026-11-15 17:00', 'Asia/Tokyo'));
        $this->assertTrue($ledger->tryConsumeRead(1));
    }

    public function test_remaining_today_converts_free_units_to_items(): void
    {
        app(QuotaLedger::class)->record(QuotaLedger::WRITE, 4851);
        $r = app(QuotaLedger::class)->remainingToday();
        $this->assertSame(1, $r->items);
        $this->assertFalse($r->exhausted());
        $this->assertFalse($r->covers(2));
    }

    public function test_non_positive_units_are_rejected(): void
    {
        $ledger = app(QuotaLedger::class);
        foreach ([0, -1] as $n) {
            try {
                $ledger->record(QuotaLedger::WRITE, $n);
                $this->fail('record accepted '.$n);
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
            try {
                $ledger->tryConsumeRead($n);
                $this->fail('tryConsumeRead accepted '.$n);
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(0, $ledger->used(QuotaLedger::WRITE));
    }
}

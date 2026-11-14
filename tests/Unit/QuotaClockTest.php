<?php

namespace Tests\Unit;

use App\Support\QuotaClock;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class QuotaClockTest extends TestCase
{
    public function test_reset_is_16_jst_during_daylight_time(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-01 12:00', 'Asia/Tokyo'));
        $this->assertSame('2026-07-01 16:00', QuotaClock::nextResetJst()->format('Y-m-d H:i'));
        $this->assertSame('Asia/Tokyo', QuotaClock::nextResetJst()->timezoneName);
    }

    public function test_reset_is_17_jst_during_standard_time(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-12-01 18:00', 'Asia/Tokyo'));
        $this->assertSame('2026-12-02 17:00', QuotaClock::nextResetJst()->format('Y-m-d H:i'));
    }

    public function test_reset_across_dst_end(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-01 12:00', 'Asia/Tokyo'));
        $this->assertSame('2026-11-01 16:00', QuotaClock::nextResetJst()->format('Y-m-d H:i'));
        $this->travelTo(CarbonImmutable::parse('2026-11-01 17:00', 'Asia/Tokyo'));
        $this->assertSame('2026-11-02 17:00', QuotaClock::nextResetJst()->format('Y-m-d H:i'));
    }

    public function test_today_pt_changes_at_pacific_midnight(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-01 15:59', 'Asia/Tokyo'));
        $this->assertSame('2026-10-31', QuotaClock::todayPt());
        $this->travelTo(CarbonImmutable::parse('2026-11-01 16:00', 'Asia/Tokyo'));
        $this->assertSame('2026-11-01', QuotaClock::todayPt());
    }

    public function test_reset_across_dst_start(): void // 2026-03-08 に夏時間開始
    {
        $this->travelTo(CarbonImmutable::parse('2026-03-08 12:00', 'Asia/Tokyo'));
        $this->assertSame('2026-03-08 17:00', QuotaClock::nextResetJst()->format('Y-m-d H:i'));
        $this->travelTo(CarbonImmutable::parse('2026-03-08 17:00', 'Asia/Tokyo'));
        $this->assertSame('2026-03-09 16:00', QuotaClock::nextResetJst()->format('Y-m-d H:i'));
    }
}

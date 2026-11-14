<?php

namespace Tests\Unit;

use App\Support\DurationFormat;
use PHPUnit\Framework\TestCase;

class DurationFormatTest extends TestCase
{
    public function test_clock(): void
    {
        $this->assertSame('1:05', DurationFormat::clock(65));
        $this->assertSame('1:02:03', DurationFormat::clock(3723));
        $this->assertSame('0:00', DurationFormat::clock(0));
        $this->assertSame('0:09', DurationFormat::clock(9));
        $this->assertSame('10:00:00', DurationFormat::clock(36000));
        $this->assertSame('', DurationFormat::clock(null));
    }

    public function test_total(): void
    {
        $this->assertSame('1 時間 2 分', DurationFormat::total(3723));
        $this->assertSame('1 分', DurationFormat::total(65));
        $this->assertSame('1 分未満', DurationFormat::total(30));
        $this->assertSame('1 分未満', DurationFormat::total(0));
        $this->assertSame('2 時間', DurationFormat::total(7200));
        $this->assertSame('59 分', DurationFormat::total(3599));
    }
}

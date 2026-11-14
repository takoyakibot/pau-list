<?php

namespace Tests\Unit;

use App\Support\IsoDuration;
use PHPUnit\Framework\TestCase;

class IsoDurationTest extends TestCase
{
    public function test_to_seconds(): void
    {
        $this->assertSame(3723, IsoDuration::toSeconds('PT1H2M3S'));
        $this->assertSame(45, IsoDuration::toSeconds('PT45S'));
        $this->assertSame(86401, IsoDuration::toSeconds('P1DT1S'));
        $this->assertSame(0, IsoDuration::toSeconds('P0D'));
        $this->assertNull(IsoDuration::toSeconds('x'));
        $this->assertNull(IsoDuration::toSeconds(null));
        $this->assertNull(IsoDuration::toSeconds('P'));
        $this->assertNull(IsoDuration::toSeconds('PT'));
    }
}

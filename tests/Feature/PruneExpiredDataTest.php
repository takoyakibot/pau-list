<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class PruneExpiredDataTest extends TestCase
{
    public function test_command_runs_successfully(): void
    {
        $this->artisan('app:prune-expired')->assertSuccessful();
    }

    public function test_scheduled_daily_at_four_jst(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($e) => str_contains((string) $e->command, 'app:prune-expired'));

        $this->assertCount(1, $events);
        $event = $events->first();
        $this->assertSame('0 4 * * *', $event->expression);
        $this->assertSame('Asia/Tokyo', (string) $event->timezone);
        $this->assertTrue($event->withoutOverlapping);
    }
}

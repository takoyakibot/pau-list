<?php

namespace Tests\Feature;

use App\Support\OwnerSession;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesProposals;
use Tests\TestCase;

/**
 * Final review of Phase 4 (policy I3): an expired owner login (token) and a stale unlisted list leave the session
 * on the next request of any page, and stale session rows are deleted every hour.
 */
class DropExpiredOwnerDataTest extends TestCase
{
    use CreatesProposals, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-15 03:00:00'));
    }

    private function unlisted(int $ageSeconds): array
    {
        return ['channel_id' => $this->channelId, 'fetched_at' => now()->getTimestamp() - $ageSeconds, 'videos' => [['id' => 'x']], 'truncated' => false];
    }

    public function test_expired_login_and_its_unlisted_list_are_dropped_on_any_page(): void
    {
        $this->actAsOwner($this->channelId, expiresAt: now()->toImmutable()->subSecond())
            ->withSession([OwnerSession::UNLISTED_KEY => $this->unlisted(10)])
            ->get('/privacy')->assertOk()
            ->assertSessionMissing(OwnerSession::OWNER_KEY)
            ->assertSessionMissing(OwnerSession::UNLISTED_KEY);
    }

    public function test_stale_unlisted_list_is_dropped_and_a_valid_login_kept(): void
    {
        $this->actAsOwner($this->channelId)
            ->withSession([OwnerSession::UNLISTED_KEY => $this->unlisted(OwnerSession::UNLISTED_TTL_SECONDS)])
            ->get('/')->assertOk()
            ->assertSessionHas(OwnerSession::OWNER_KEY)
            ->assertSessionMissing(OwnerSession::UNLISTED_KEY);
    }

    public function test_fresh_data_is_kept(): void
    {
        $this->actAsOwner($this->channelId)
            ->withSession([OwnerSession::UNLISTED_KEY => $this->unlisted(OwnerSession::UNLISTED_TTL_SECONDS - 1)])
            ->get('/')->assertOk()
            ->assertSessionHas(OwnerSession::OWNER_KEY)
            ->assertSessionHas(OwnerSession::UNLISTED_KEY);
    }

    public function test_unlisted_list_without_a_login_is_dropped(): void
    {
        $this->withSession([OwnerSession::UNLISTED_KEY => $this->unlisted(10)])
            ->get('/')->assertOk()
            ->assertSessionMissing(OwnerSession::UNLISTED_KEY);
    }

    public function test_stale_sessions_are_deleted_every_hour(): void
    {
        $t = now()->getTimestamp();
        DB::table('sessions')->insert([
            ['id' => 'stale', 'user_id' => null, 'payload' => '', 'last_activity' => $t - 121 * 60],
            ['id' => 'fresh', 'user_id' => null, 'payload' => '', 'last_activity' => $t - 119 * 60],
        ]);

        $this->artisan('app:prune-sessions')->expectsOutput('sessions: 1')->assertSuccessful();

        $this->assertSame(['fresh'], DB::table('sessions')->pluck('id')->all());
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($e) => str_contains((string) $e->command, 'app:prune-sessions'));
        $this->assertCount(1, $events);
        $this->assertSame('0 * * * *', $events->first()->expression);
        $this->assertTrue($events->first()->withoutOverlapping);
    }
}

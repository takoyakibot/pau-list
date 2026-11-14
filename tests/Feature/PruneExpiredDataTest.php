<?php

namespace Tests\Feature;

use App\Models\ChannelCache;
use App\Models\Proposal;
use App\Support\ProposalKey;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class PruneExpiredDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // 2026-11-14 03:00Z: PT date 2026-11-13
        $this->travelTo(CarbonImmutable::parse('2026-11-14 03:00:00'));
    }

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

    public function test_only_prune_is_scheduled(): void
    {
        // No periodic re-fetch job (spec 10.5)
        $events = app(Schedule::class)->events();
        $this->assertCount(1, $events);
        $this->assertStringContainsString('app:prune-expired', (string) $events[0]->command);
    }

    private function proposal(CarbonInterface $createdAt, string $videoId): Proposal
    {
        $createdAt = CarbonImmutable::instance($createdAt);
        $p = Proposal::create([
            'key_hash' => ProposalKey::hash(ProposalKey::generate()),
            'channel_id' => 'UC'.str_repeat('a', 22),
            'channel_title' => 'c',
            'title' => 't',
            'description' => '',
            'item_count' => 1,
            'created_at' => $createdAt,
            'expires_at' => $createdAt->addDays(30),
        ]);
        $p->items()->create([
            'position' => 1,
            'video_id' => $videoId,
            'video_channel_id' => 'UC'.str_repeat('a', 22),
            'video_channel_title' => 'c',
            'is_outside_channel' => false,
            'title' => 'v',
        ]);

        return $p;
    }

    public function test_deletes_proposals_expiring_before_next_run_with_items(): void
    {
        $kept = $this->proposal(now()->subDays(28), 'aaaaaaaaaaa');
        // expires_at = now + 24h + 1s: survives this run (the next daily run deletes it before it expires + 1 day)
        $keptEdge = $this->proposal(now()->subDays(29)->addSecond(), 'ddddddddddd');
        // expires_at = now + 24h: deleted now, since the next run would be too late
        $edge = $this->proposal(now()->subDays(29), 'eeeeeeeeeee');
        $gone = $this->proposal(now()->subDays(30), 'bbbbbbbbbbb');
        $older = $this->proposal(now()->subDays(45), 'ccccccccccc');

        $this->artisan('app:prune-expired')->expectsOutput('proposals: 3')->assertSuccessful();

        $this->assertSame([$kept->id, $keptEdge->id], Proposal::orderBy('id')->pluck('id')->all());
        $this->assertSame(['aaaaaaaaaaa', 'ddddddddddd'], DB::table('proposal_items')->orderBy('video_id')->pluck('video_id')->all());
        $this->assertSame(0, DB::table('proposal_items')->whereIn('proposal_id', [$edge->id, $gone->id, $older->id])->count());
    }

    private function channel(string $id, CarbonInterface $cachedSince): void
    {
        $cachedSince = CarbonImmutable::instance($cachedSince);
        ChannelCache::create([
            'channel_id' => $id,
            'handle' => null,
            'title' => 'ch',
            'thumbnail_url' => null,
            'uploads_playlist_id' => 'UU'.substr($id, 2),
            'meta_fetched_at' => $cachedSince,
            'cached_since' => $cachedSince,
            'last_synced_at' => $cachedSince,
            'truncated' => false,
            'expires_at' => $cachedSince->addDays(30),
        ]);
        // one row from the full import, one added by a later diff import
        DB::table('channel_cache_videos')->insert([
            ['channel_id' => $id, 'video_id' => substr($id, 2, 10).'1', 'title' => 'v1', 'thumbnail_url' => null, 'published_at' => $cachedSince, 'fetched_at' => $cachedSince],
            ['channel_id' => $id, 'video_id' => substr($id, 2, 10).'2', 'title' => 'v2', 'thumbnail_url' => null, 'published_at' => $cachedSince->addDays(20), 'fetched_at' => $cachedSince->addDays(20)],
        ]);
    }

    public function test_expires_channel_caches_as_unit_and_records(): void
    {
        $old = 'UC'.str_repeat('o', 22);
        $live = 'UC'.str_repeat('l', 22);
        $this->channel($old, now()->subDays(30));
        $this->channel($live, now()->subDays(28));

        $this->artisan('app:prune-expired')->expectsOutput('channel_caches: 1')->assertSuccessful();

        $this->assertSame([$live], ChannelCache::pluck('channel_id')->all());
        $this->assertSame(0, DB::table('channel_cache_videos')->where('channel_id', $old)->count());
        $this->assertSame(2, DB::table('channel_cache_videos')->where('channel_id', $live)->count());
        $records = DB::table('channel_cache_expirations')->get();
        $this->assertCount(1, $records);
        $this->assertSame($old, $records[0]->channel_id);
        $this->assertSame(['channel_id', 'expired_at'], Schema::getColumnListing('channel_cache_expirations'));
        $this->assertSame(now()->format('Y-m-d H:i:s'), CarbonImmutable::parse($records[0]->expired_at)->format('Y-m-d H:i:s'));
    }

    public function test_channel_cache_boundary_is_now_plus_24_hours(): void
    {
        $edge = 'UC'.str_repeat('e', 22);
        $keptEdge = 'UC'.str_repeat('f', 22);
        $this->channel($edge, now()->subDays(29));                 // expires_at = now + 24h
        $this->channel($keptEdge, now()->subDays(29)->addSecond()); // expires_at = now + 24h + 1s

        $this->artisan('app:prune-expired')->expectsOutput('channel_caches: 1')->assertSuccessful();

        $this->assertSame([$keptEdge], ChannelCache::pluck('channel_id')->all());
        $this->assertSame(0, DB::table('channel_cache_videos')->where('channel_id', $edge)->count());
        $this->assertSame([$edge], DB::table('channel_cache_expirations')->pluck('channel_id')->all());
    }

    public function test_channel_locked_by_an_import_is_retried_after_waiting_for_the_lock(): void
    {
        Sleep::fake(syncWithCarbon: true);
        $locked = 'UC'.str_repeat('k', 22);
        $free = 'UC'.str_repeat('m', 22);
        $this->channel($locked, now()->subDays(29));
        $this->channel($free, now()->subDays(31));
        // an import holding the lock for 20 more seconds
        $this->assertTrue(Cache::lock("channel-fetch:{$locked}", 20)->get());

        $this->artisan('app:prune-expired')->expectsOutput('channel_caches: 2')->assertSuccessful();

        $this->assertSame(0, ChannelCache::count());
        $this->assertSame(0, DB::table('channel_cache_videos')->count());
        $this->assertSame(2, DB::table('channel_cache_expirations')->count());
    }

    public function test_channel_whose_lock_never_frees_is_left_for_the_next_run(): void
    {
        Sleep::fake(syncWithCarbon: true);
        $id = 'UC'.str_repeat('k', 22);
        $this->channel($id, now()->subDays(31));
        $lock = Cache::lock("channel-fetch:{$id}", 600);
        $this->assertTrue($lock->get());
        $start = now()->toImmutable();

        $this->artisan('app:prune-expired')
            ->expectsOutput('channel_caches: 0')
            ->expectsOutput('sessions: 0')
            ->assertSuccessful();
        $this->assertSame(1, ChannelCache::count());
        $this->assertLessThanOrEqual(61, (int) $start->diffInSeconds(now()));

        $lock->release();
        $this->artisan('app:prune-expired')->expectsOutput('channel_caches: 1')->assertSuccessful();
        $this->assertSame(0, ChannelCache::count());
    }

    public function test_deletes_expiration_records_after_30_days(): void
    {
        DB::table('channel_cache_expirations')->insert([
            ['channel_id' => 'UC'.str_repeat('a', 22), 'expired_at' => now()->subDays(30)],
            // expired_at + 30 days = now + 24h → deleted
            ['channel_id' => 'UC'.str_repeat('b', 22), 'expired_at' => now()->subDays(29)],
            // expired_at + 30 days = now + 24h + 1s → kept
            ['channel_id' => 'UC'.str_repeat('c', 22), 'expired_at' => now()->subDays(29)->addSecond()],
            ['channel_id' => 'UC'.str_repeat('d', 22), 'expired_at' => now()->subDays(28)],
        ]);

        $this->artisan('app:prune-expired')->expectsOutput('channel_cache_expirations: 2')->assertSuccessful();

        $this->assertSame(['UC'.str_repeat('c', 22), 'UC'.str_repeat('d', 22)], DB::table('channel_cache_expirations')->orderBy('channel_id')->pluck('channel_id')->all());
    }

    public function test_record_created_by_this_run_is_kept(): void
    {
        $this->channel('UC'.str_repeat('o', 22), now()->subDays(40));

        $this->artisan('app:prune-expired')
            ->expectsOutput('channel_caches: 1')
            ->expectsOutput('channel_cache_expirations: 0')
            ->assertSuccessful();
        $this->assertSame(1, DB::table('channel_cache_expirations')->count());
    }

    public function test_deletes_quota_usage_older_than_90_days(): void
    {
        // today (PT) = 2026-11-13; 90 days before = 2026-08-15
        DB::table('quota_usage')->insert([
            ['day_pt' => '2026-08-14', 'bucket' => 'read', 'units' => 1],
            ['day_pt' => '2026-08-14', 'bucket' => 'write', 'units' => 1],
            ['day_pt' => '2026-08-15', 'bucket' => 'read', 'units' => 1],
            ['day_pt' => '2026-11-13', 'bucket' => 'read', 'units' => 1],
        ]);

        $this->artisan('app:prune-expired')->expectsOutput('quota_usage: 2')->assertSuccessful();

        $this->assertSame(['2026-08-15', '2026-11-13'], DB::table('quota_usage')->orderBy('day_pt')->pluck('day_pt')->all());
    }

    public function test_deletes_expired_cache_rows_and_stale_sessions(): void
    {
        $t = now()->getTimestamp();
        DB::table('cache')->insert([
            ['key' => 'old', 'value' => 's:1:"x";', 'expiration' => $t],
            ['key' => 'live', 'value' => 's:1:"x";', 'expiration' => $t + 60],
        ]);
        DB::table('cache_locks')->insert([
            ['key' => 'old-lock', 'owner' => 'o', 'expiration' => $t - 1],
            ['key' => 'live-lock', 'owner' => 'o', 'expiration' => $t + 60],
        ]);
        // SESSION_LIFETIME = 120 minutes
        DB::table('sessions')->insert([
            ['id' => 'stale', 'user_id' => null, 'payload' => '', 'last_activity' => $t - 121 * 60],
            ['id' => 'fresh', 'user_id' => null, 'payload' => '', 'last_activity' => $t - 119 * 60],
        ]);

        $this->artisan('app:prune-expired')
            ->expectsOutput('cache: 1')
            ->expectsOutput('cache_locks: 1')
            ->expectsOutput('sessions: 1')
            ->assertSuccessful();

        $this->assertSame(['live'], DB::table('cache')->pluck('key')->all());
        $this->assertSame(['live-lock'], DB::table('cache_locks')->pluck('key')->all());
        $this->assertSame(['fresh'], DB::table('sessions')->pluck('id')->all());
    }

    public function test_failing_step_does_not_stop_the_others_and_exits_non_zero(): void
    {
        $this->proposal(now()->subDays(31), 'aaaaaaaaaaa');
        DB::table('sessions')->insert(['id' => 'stale', 'user_id' => null, 'payload' => '', 'last_activity' => now()->getTimestamp() - 121 * 60]);
        Schema::drop('quota_usage');
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
            $logged[] = $e->message.' '.json_encode($e->context);
        });

        $this->artisan('app:prune-expired')
            ->expectsOutput('proposals: 1')
            ->expectsOutput('quota_usage: failed')
            ->expectsOutput('sessions: 1')
            ->assertFailed();

        $this->assertSame(0, Proposal::count());
        $this->assertSame(0, DB::table('sessions')->count());
        $this->assertCount(1, $logged);
        $this->assertStringContainsString('Prune step failed', $logged[0]);
        $this->assertStringContainsString('"step":"quota_usage"', $logged[0]);
        $this->assertStringContainsString('"sqlstate":"HY000"', $logged[0]);
        $this->assertStringNotContainsStringIgnoringCase('delete from', $logged[0]);
        $this->assertStringNotContainsString('2026-', $logged[0]);
    }

    public function test_is_idempotent(): void
    {
        $this->proposal(now()->subDays(31), 'aaaaaaaaaaa');
        $this->channel('UC'.str_repeat('o', 22), now()->subDays(31));

        $this->artisan('app:prune-expired')->expectsOutput('proposals: 1')->expectsOutput('channel_caches: 1')->assertSuccessful();
        $this->artisan('app:prune-expired')
            ->expectsOutput('proposals: 0')
            ->expectsOutput('channel_caches: 0')
            ->expectsOutput('channel_cache_expirations: 0')
            ->assertSuccessful();
        $this->assertSame(1, DB::table('channel_cache_expirations')->count());
    }
}

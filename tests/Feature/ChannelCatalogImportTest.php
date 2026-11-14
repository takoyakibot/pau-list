<?php

namespace Tests\Feature;

use App\Models\ChannelCache;
use App\Services\CatalogStatus;
use App\Services\ChannelVideoCatalog;
use App\Services\YouTube\QuotaLedger;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\FakesYouTube;
use Tests\TestCase;

class ChannelCatalogImportTest extends TestCase
{
    use FakesYouTube, RefreshDatabase;

    private string $id;

    private string $uu;

    protected function setUp(): void
    {
        parent::setUp();
        $this->id = 'UC'.str_repeat('a', 22);
        $this->uu = 'UU'.str_repeat('a', 22);
    }

    /** Newest-first upload items v0000000000.. with descending publish times. */
    private function items(int $start, int $n, string $privacy = 'public'): array
    {
        $out = [];
        for ($i = $start; $i < $start + $n; $i++) {
            $out[] = $this->uploadItem(
                sprintf('v%010d', $i),
                CarbonImmutable::parse('2026-11-13 00:00:00')->subMinutes($i)->format('Y-m-d\TH:i:s\Z'),
                $privacy,
            );
        }

        return $out;
    }

    private function resetHttp(): void
    {
        Http::swap(new Factory(app('events')));
        Http::preventStrayRequests();
    }

    private function catalog(): ChannelVideoCatalog
    {
        return app(ChannelVideoCatalog::class);
    }

    private function importInitial(): void
    {
        $this->fakeChannel($this->id);
        $this->fakeUploads($this->uu, [$this->items(0, 20)]);
        $this->assertSame(CatalogStatus::Imported, $this->catalog()->load($this->id)->status);
    }

    public function test_first_load_imports_all_pages_until_end(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-14 03:00:00'));
        $this->fakeChannel($this->id, ['title' => 'My Channel', 'handle' => '@MyHandle']);
        $this->fakeUploads($this->uu, [$this->items(0, 50), $this->items(50, 20)]);

        $r = $this->catalog()->load($this->id);

        $this->assertSame(CatalogStatus::Imported, $r->status);
        $this->assertFalse($r->hadExpired);
        $this->assertSame(70, $r->channel->videos()->count());
        $this->assertFalse($r->channel->truncated);
        $this->assertTrue($r->channel->cached_since->equalTo(now()));
        $this->assertTrue($r->channel->last_synced_at->equalTo(now()));
        $this->assertTrue($r->channel->meta_fetched_at->equalTo(now()));
        $this->assertTrue($r->channel->expires_at->equalTo(now()->addDays(30)));
        $this->assertSame('My Channel', $r->channel->title);
        $this->assertSame('myhandle', $r->channel->handle);
        $this->assertSame($this->uu, $r->channel->uploads_playlist_id);
        $first = $r->channel->videos()->where('video_id', 'v0000000000')->first();
        $this->assertSame('Video v0000000000', $first->title);
        $this->assertTrue($first->published_at->equalTo(CarbonImmutable::parse('2026-11-13 00:00:00')));
        $this->assertTrue($first->fetched_at->equalTo(now()));
    }

    public function test_full_import_stops_at_20_pages_and_marks_truncated(): void
    {
        $pages = [];
        for ($p = 0; $p < 21; $p++) {
            $pages[] = $this->items($p * 50, 50);
        }
        $this->fakeChannel($this->id);
        $this->fakeUploads($this->uu, $pages);

        $r = $this->catalog()->load($this->id);

        $this->assertSame(CatalogStatus::Imported, $r->status);
        $this->assertCount(21, Http::recorded());
        $this->assertCount(20, Http::recorded(fn ($req) => str_contains($req->url(), 'playlistItems')));
        $this->assertSame(1000, $r->channel->videos()->count());
        $this->assertTrue($r->channel->truncated);
        $this->assertSame(21, app(QuotaLedger::class)->used(QuotaLedger::READ));
    }

    public function test_duplicate_ids_are_imported_once(): void
    {
        $this->fakeChannel($this->id);
        $this->fakeUploads($this->uu, [$this->items(0, 5), $this->items(3, 5)]);

        $r = $this->catalog()->load($this->id);

        $this->assertSame(8, $r->channel->videos()->count());
    }

    public function test_reuses_cache_within_6_hours_without_api_calls(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-14 03:00:00'));
        $this->importInitial();
        $this->resetHttp();
        Http::fake();

        $this->travelTo(CarbonImmutable::parse('2026-11-14 08:59:00'));
        $this->assertTrue($this->catalog()->isReusable($this->id));
        $r = $this->catalog()->load($this->id);

        $this->assertSame(CatalogStatus::Reused, $r->status);
        $this->assertSame($this->id, $r->channel->channel_id);
        $this->assertSame(20, $r->channel->videos()->count());
        Http::assertNothingSent();

        $this->travelTo(CarbonImmutable::parse('2026-11-14 09:01:00'));
        $this->assertFalse($this->catalog()->isReusable($this->id));
    }

    public function test_private_items_are_not_imported(): void
    {
        $this->fakeChannel($this->id);
        $this->fakeUploads($this->uu, [array_merge(
            $this->items(0, 2),
            $this->items(2, 1, 'private'),
            $this->items(3, 1, 'unlisted'),
            $this->items(4, 1),
        )]);

        $r = $this->catalog()->load($this->id);

        $this->assertSame(
            ['v0000000000', 'v0000000001', 'v0000000004'],
            $r->channel->videos()->orderBy('video_id')->pluck('video_id')->all(),
        );
    }

    public function test_channel_without_uploads_imports_empty_list(): void
    {
        $this->fakeChannel($this->id);
        $this->fakeUploads($this->uu, []);

        $r = $this->catalog()->load($this->id);

        $this->assertSame(CatalogStatus::Imported, $r->status);
        $this->assertSame(0, $r->channel->videos()->count());
        $this->assertFalse($r->channel->truncated);
    }

    public function test_unknown_channel_is_not_found_and_writes_nothing(): void
    {
        $this->fakeChannel($this->id, ['missing' => true]);

        $r = $this->catalog()->load($this->id);

        $this->assertSame(CatalogStatus::NotFound, $r->status);
        $this->assertNull($r->channel);
        $this->assertSame(0, DB::table('channel_caches')->count());
    }

    public function test_api_error_on_first_import_writes_nothing(): void
    {
        $this->fakeChannel($this->id);
        $this->fakeYouTubeError('playlistItems', 500, 'backendError');

        $r = $this->catalog()->load($this->id);

        $this->assertSame(CatalogStatus::Failed, $r->status);
        $this->assertNull($r->channel);
        $this->assertSame(0, DB::table('channel_caches')->count());
        $this->assertSame(0, DB::table('channel_cache_videos')->count());
    }

    public function test_channel_expires_as_a_unit_after_30_days_and_is_recorded(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-14 03:00:00'));
        $this->importInitial();
        DB::table('channel_cache_videos')->insert([
            'channel_id' => $this->id,
            'video_id' => 'x0000000001',
            'title' => 'Added later',
            'thumbnail_url' => null,
            'published_at' => '2026-11-19 00:00:00',
            'fetched_at' => '2026-11-20 00:00:00',
        ]);

        $this->travelTo(CarbonImmutable::parse('2026-12-14 02:59:59'));
        $this->assertSame(0, $this->catalog()->expireStale());
        $this->assertSame(1, DB::table('channel_caches')->count());
        $this->assertSame(21, DB::table('channel_cache_videos')->count());

        $this->travelTo(CarbonImmutable::parse('2026-12-14 03:00:00'));
        $this->assertSame(1, $this->catalog()->expireStale());
        $this->assertSame(0, DB::table('channel_caches')->count());
        $this->assertSame(0, DB::table('channel_cache_videos')->count());
        $rows = DB::table('channel_cache_expirations')->get();
        $this->assertCount(1, $rows);
        $this->assertSame($this->id, $rows[0]->channel_id);
        $this->assertTrue(CarbonImmutable::parse($rows[0]->expired_at)->equalTo(now()));
    }

    public function test_expired_cache_is_not_reusable_before_batch(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-14 03:00:00'));
        $this->importInitial();
        ChannelCache::query()->update(['last_synced_at' => '2026-12-14 02:00:00']);

        $this->travelTo(CarbonImmutable::parse('2026-12-14 03:00:00'));
        $this->assertFalse($this->catalog()->isReusable($this->id));
    }

    public function test_load_after_expiry_reimports_and_clears_record(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-14 03:00:00'));
        $this->importInitial();

        $this->travelTo(CarbonImmutable::parse('2026-12-15 03:00:00'));
        $this->resetHttp();
        $this->fakeChannel($this->id);
        $this->fakeUploads($this->uu, [$this->items(0, 25)]);

        $r = $this->catalog()->load($this->id);

        $this->assertSame(CatalogStatus::Imported, $r->status);
        $this->assertTrue($r->hadExpired);
        $this->assertTrue($r->channel->cached_since->equalTo(now()));
        $this->assertTrue($r->channel->expires_at->equalTo(now()->addDays(30)));
        $this->assertSame(25, $r->channel->videos()->count());
        $this->assertSame(0, DB::table('channel_cache_expirations')->count());
    }

    public function test_budget_exhausted_without_cache_writes_nothing(): void
    {
        config(['youtube.quota.read_budget' => 0]);
        Http::fake();

        $r = $this->catalog()->load($this->id);

        $this->assertSame(CatalogStatus::BudgetExhausted, $r->status);
        $this->assertNull($r->channel);
        $this->assertFalse($r->hadExpired);
        $this->assertSame(0, DB::table('channel_caches')->count());
        Http::assertNothingSent();
    }

    public function test_budget_exhausted_after_expiry_reports_expired_record(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-14 03:00:00'));
        $this->importInitial();
        $this->travelTo(CarbonImmutable::parse('2026-12-15 03:00:00'));
        config(['youtube.quota.read_budget' => 0]);

        $r = $this->catalog()->load($this->id);

        $this->assertSame(CatalogStatus::BudgetExhausted, $r->status);
        $this->assertNull($r->channel);
        $this->assertTrue($r->hadExpired);
        $this->assertSame(0, DB::table('channel_caches')->count());
        $this->assertSame(1, DB::table('channel_cache_expirations')->count());
    }

    public function test_reuse_does_not_wait_for_the_fetch_lock(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-14 03:00:00'));
        $this->importInitial();
        $this->travel(1)->hours();
        $this->resetHttp();
        Http::fake();
        Sleep::fake();
        $this->assertTrue(Cache::lock('channel-fetch:'.$this->id, 60)->get());

        $r = $this->catalog()->load($this->id);

        $this->assertSame(CatalogStatus::Reused, $r->status);
        $this->assertSame($this->id, $r->channel->channel_id);
        Http::assertNothingSent();
        Sleep::assertNeverSlept();
    }

    public function test_lock_timeout_returns_failed_with_live_row(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-14 03:00:00'));
        $this->importInitial();
        $this->travel(7)->hours();
        $this->resetHttp();
        Http::fake();
        Sleep::fake(syncWithCarbon: true);
        $this->assertTrue(Cache::lock('channel-fetch:'.$this->id, 60)->get());
        $start = now()->toImmutable();

        $r = $this->catalog()->load($this->id);

        $this->assertSame(CatalogStatus::Failed, $r->status);
        $this->assertSame($this->id, $r->channel->channel_id);
        $this->assertGreaterThanOrEqual(39, $start->diffInSeconds(now())); // waited the full 40 s block (faked sleep)
        Http::assertNothingSent();
    }

    public function test_lock_timeout_does_not_return_an_expired_row(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-14 03:00:00'));
        $this->importInitial();
        $this->travelTo(CarbonImmutable::parse('2026-12-15 03:00:00'));
        $this->resetHttp();
        Http::fake();
        Sleep::fake(syncWithCarbon: true);
        $this->assertTrue(Cache::lock('channel-fetch:'.$this->id, 60)->get());

        $r = $this->catalog()->load($this->id);

        $this->assertSame(CatalogStatus::Failed, $r->status);
        $this->assertNull($r->channel);
        Http::assertNothingSent();
    }

    public function test_expire_stale_skips_channel_being_imported(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-14 03:00:00'));
        $this->importInitial();
        $this->travelTo(CarbonImmutable::parse('2026-12-14 03:00:00'));
        Sleep::fake(syncWithCarbon: true);
        // held longer than expireStale waits (the lock TTL): skipped after waiting
        $lock = Cache::lock('channel-fetch:'.$this->id, 600);
        $this->assertTrue($lock->get());

        $this->assertSame(0, $this->catalog()->expireStale());
        $this->assertSame(1, DB::table('channel_caches')->count());

        $lock->release();
        $this->assertSame(1, $this->catalog()->expireStale());
        $this->assertSame(0, DB::table('channel_caches')->count());
    }
}

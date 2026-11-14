<?php

namespace Tests\Feature;

use App\Models\ChannelCache;
use App\Services\CatalogStatus;
use App\Services\ChannelVideoCatalog;
use App\Services\YouTube\QuotaLedger;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakesYouTube;
use Tests\TestCase;

class ChannelCatalogDiffTest extends TestCase
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

    /** Known videos k0000000000.. (published 11/13 and older, newest first). */
    private function known(int $start, int $n, string $privacy = 'public', ?string $title = null): array
    {
        return $this->make('k', '2026-11-13 00:00:00', $start, $n, $privacy, $title);
    }

    /** New videos n0000000000.. (published 11/14, newer than every known video, newest first). */
    private function fresh(int $start, int $n, string $privacy = 'public'): array
    {
        return $this->make('n', '2026-11-14 08:00:00', $start, $n, $privacy);
    }

    private function make(string $prefix, string $base, int $start, int $n, string $privacy, ?string $title = null): array
    {
        $out = [];
        for ($i = $start; $i < $start + $n; $i++) {
            $id = sprintf('%s%010d', $prefix, $i);
            $out[] = $this->uploadItem(
                $id,
                CarbonImmutable::parse($base)->subMinutes($i)->format('Y-m-d\TH:i:s\Z'),
                $privacy,
                $title,
            );
        }

        return $out;
    }

    private function catalog(): ChannelVideoCatalog
    {
        return app(ChannelVideoCatalog::class);
    }

    /** 11/14 03:00 UTC: full import of the given known videos, then 7 hours later with fresh HTTP fakes. */
    private function importThenAdvance(int $knownCount = 20): ChannelCache
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-14 03:00:00'));
        $this->fakeChannel($this->id);
        $this->fakeUploads($this->uu, $knownCount === 0 ? [] : [$this->known(0, $knownCount)]);
        $r = $this->catalog()->load($this->id);
        $this->assertSame(CatalogStatus::Imported, $r->status);

        $this->travel(7)->hours();
        Http::swap(new Factory(app('events')));
        Http::preventStrayRequests();
        $this->fakeChannel($this->id);

        return $r->channel;
    }

    private function ids(): array
    {
        return DB::table('channel_cache_videos')->orderBy('video_id')->pluck('video_id')->all();
    }

    private function playlistRequests(): int
    {
        return count(Http::recorded(fn (Request $r) => str_contains($r->url(), 'playlistItems')));
    }

    private function channelRequests(): int
    {
        return count(Http::recorded(fn (Request $r) => str_contains($r->url(), '/channels?')));
    }

    public function test_three_new_then_known_stops_on_first_page(): void
    {
        $before = $this->importThenAdvance();
        $fetchedBefore = DB::table('channel_cache_videos')->where('video_id', 'k0000000000')->value('fetched_at');
        $this->fakeUploads($this->uu, [
            array_merge($this->fresh(0, 3), $this->known(0, 20, 'public', 'Retitled')),
            $this->fresh(100, 5),
        ]);

        $r = $this->catalog()->load($this->id);

        $this->assertSame(CatalogStatus::Synced, $r->status);
        $this->assertSame(1, $this->channelRequests());
        $this->assertSame(1, $this->playlistRequests());
        $this->assertSame(2, app(QuotaLedger::class)->used(QuotaLedger::READ)); // 02:00 PT: new PT day since the import
        $this->assertSame(23, $r->channel->videos()->count());
        $known = $r->channel->videos()->where('video_id', 'k0000000000')->first();
        $this->assertSame('Video k0000000000', $known->title);
        $this->assertSame($fetchedBefore, DB::table('channel_cache_videos')->where('video_id', 'k0000000000')->value('fetched_at'));
        $this->assertTrue($r->channel->videos()->where('video_id', 'n0000000000')->first()->fetched_at->equalTo(now()));
        $this->assertTrue($r->channel->cached_since->equalTo($before->cached_since));
        $this->assertTrue($r->channel->expires_at->equalTo($before->expires_at));
        $this->assertTrue($r->channel->last_synced_at->equalTo(now()));
        $this->assertFalse($r->hadExpired);
    }

    public function test_sixty_new_reads_second_page(): void
    {
        $this->importThenAdvance();
        $this->fakeUploads($this->uu, [
            $this->fresh(0, 50),
            array_merge($this->fresh(50, 10), $this->known(0, 20)),
            $this->fresh(200, 5),
        ]);

        $r = $this->catalog()->load($this->id);

        $this->assertSame(CatalogStatus::Synced, $r->status);
        $this->assertSame(2, $this->playlistRequests());
        $this->assertSame(80, $r->channel->videos()->count());
    }

    public function test_one_or_two_known_on_top_do_not_stop(): void
    {
        $this->importThenAdvance();
        $page1 = array_merge(
            $this->known(0, 2),
            $this->fresh(0, 2),
            $this->known(2, 3),
        );
        $this->fakeUploads($this->uu, [$page1, $this->fresh(10, 1)]);

        $r = $this->catalog()->load($this->id);

        $this->assertSame(CatalogStatus::Synced, $r->status);
        $this->assertSame(1, $this->playlistRequests());
        $this->assertSame(22, $r->channel->videos()->count());
        $this->assertContains('n0000000000', $this->ids());
        $this->assertContains('n0000000001', $this->ids());
        $this->assertNotContains('n0000000010', $this->ids());
    }

    public function test_known_run_resets_on_new_item_and_continues_to_next_page(): void
    {
        $this->importThenAdvance();
        // known 2 in a row then a new one: run resets, page ends without 3 in a row -> read page 2.
        $this->fakeUploads($this->uu, [
            array_merge($this->known(0, 2), $this->fresh(0, 1)),
            array_merge($this->fresh(1, 1), $this->known(2, 3)),
            $this->fresh(10, 1),
        ]);

        $r = $this->catalog()->load($this->id);

        $this->assertSame(2, $this->playlistRequests());
        $this->assertSame(22, $r->channel->videos()->count());
    }

    public function test_channel_with_two_rows_stops_when_both_seen(): void
    {
        $this->importThenAdvance(2);
        $this->fakeUploads($this->uu, [
            array_merge($this->fresh(0, 1), $this->known(0, 2)),
            $this->fresh(10, 1),
        ]);

        $r = $this->catalog()->load($this->id);

        $this->assertSame(CatalogStatus::Synced, $r->status);
        $this->assertSame(1, $this->playlistRequests());
        $this->assertSame(3, $r->channel->videos()->count());
    }

    public function test_channel_without_rows_reads_only_first_page(): void
    {
        $this->importThenAdvance(0);
        $this->fakeUploads($this->uu, [$this->fresh(0, 50), $this->fresh(50, 5)]);

        $r = $this->catalog()->load($this->id);

        $this->assertSame(CatalogStatus::Synced, $r->status);
        $this->assertSame(1, $this->playlistRequests());
        $this->assertSame(50, $r->channel->videos()->count());
    }

    public function test_known_limitation_new_id_below_stop_point_is_missed_until_reimport(): void
    {
        $this->importThenAdvance();
        $this->fakeUploads($this->uu, [
            array_merge($this->known(0, 3), $this->fresh(0, 1)),
            $this->fresh(1, 1),
        ]);

        $r = $this->catalog()->load($this->id);

        $this->assertSame(CatalogStatus::Synced, $r->status);
        $this->assertContains('n0000000000', $this->ids());
        $this->assertNotContains('n0000000001', $this->ids());

        $this->travelTo(CarbonImmutable::parse('2026-12-14 03:00:00'));
        Http::swap(new Factory(app('events')));
        Http::preventStrayRequests();
        $this->fakeChannel($this->id);
        $this->fakeUploads($this->uu, [array_merge($this->fresh(0, 2), $this->known(0, 20))]);

        $r = $this->catalog()->load($this->id);

        $this->assertSame(CatalogStatus::Imported, $r->status);
        $this->assertTrue($r->hadExpired);
        $this->assertContains('n0000000001', $this->ids());
        $this->assertSame(22, $r->channel->videos()->count());
    }

    public function test_privatized_known_row_is_removed(): void
    {
        $this->importThenAdvance();
        $this->fakeUploads($this->uu, [array_merge(
            $this->fresh(0, 1, 'private'),
            $this->known(0, 1, 'private'),
            $this->known(1, 1, 'unlisted'),
            $this->known(2, 18),
        )]);

        $r = $this->catalog()->load($this->id);

        $this->assertSame(CatalogStatus::Synced, $r->status);
        $this->assertSame(18, $r->channel->videos()->count());
        $this->assertNotContains('k0000000000', $this->ids());
        $this->assertNotContains('k0000000001', $this->ids());
        $this->assertNotContains('n0000000000', $this->ids());
    }

    public function test_private_items_do_not_break_known_run(): void
    {
        $this->importThenAdvance();
        // known, known, (new private), known -> still 3 known in a row.
        $this->fakeUploads($this->uu, [
            array_merge($this->known(0, 2), $this->fresh(0, 1, 'private'), $this->known(2, 1)),
            $this->fresh(10, 1),
        ]);

        $this->catalog()->load($this->id);

        $this->assertSame(1, $this->playlistRequests());
    }

    public function test_rows_over_limit_are_trimmed_oldest_first(): void
    {
        config(['youtube.channel.fetch_max_videos' => 60]);
        $this->importThenAdvance(50);
        $this->fakeUploads($this->uu, [array_merge($this->fresh(0, 20), $this->known(0, 50))]);

        $r = $this->catalog()->load($this->id);

        $this->assertSame(CatalogStatus::Synced, $r->status);
        $this->assertSame(60, $r->channel->videos()->count());
        for ($i = 40; $i < 50; $i++) {
            $this->assertNotContains(sprintf('k%010d', $i), $this->ids());
        }
        $this->assertContains('k0000000039', $this->ids());
        $this->assertContains('n0000000019', $this->ids());
    }

    public function test_trim_breaks_publish_time_ties_by_video_id(): void
    {
        config(['youtube.channel.fetch_max_videos' => 3]);
        $this->importThenAdvance(0);
        $same = '2026-11-01T00:00:00Z';
        $this->fakeUploads($this->uu, [[
            $this->uploadItem('n0000000001', '2026-11-02T00:00:00Z'),
            $this->uploadItem('b0000000000', $same),
            $this->uploadItem('a0000000000', $same),
            $this->uploadItem('c0000000000', $same),
        ]]);

        $this->catalog()->load($this->id);

        $this->assertSame(['b0000000000', 'c0000000000', 'n0000000001'], $this->ids());
    }

    public function test_budget_exhaustion_mid_sync_writes_nothing(): void
    {
        $before = $this->importThenAdvance();
        config(['youtube.quota.read_budget' => app(QuotaLedger::class)->used(QuotaLedger::READ) + 2]);
        $this->fakeUploads($this->uu, [$this->fresh(0, 50), $this->fresh(50, 50)]);

        $r = $this->catalog()->load($this->id);

        $this->assertSame(CatalogStatus::BudgetExhausted, $r->status);
        $this->assertSame($this->id, $r->channel->channel_id);
        $this->assertSame(1, $this->playlistRequests());
        $this->assertSame(20, DB::table('channel_cache_videos')->count());
        $fresh = ChannelCache::find($this->id);
        $this->assertTrue($fresh->last_synced_at->equalTo($before->last_synced_at));
        $this->assertTrue($fresh->cached_since->equalTo($before->cached_since));
    }

    public function test_timeout_mid_import_writes_nothing(): void
    {
        $this->fakeChannel($this->id);
        $pages = [$this->fresh(0, 50), $this->fresh(50, 50), $this->fresh(100, 50)];
        Http::fake(function (Request $request) use ($pages) {
            if (! str_contains($request->url(), 'playlistItems')) {
                return null;
            }
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);
            $index = isset($q['pageToken']) ? ((int) substr($q['pageToken'], 1)) - 1 : 0;
            if ($index === 1) {
                $this->travel(31)->seconds();
            }
            $body = ['items' => $pages[$index]];
            if (isset($pages[$index + 1])) {
                $body['nextPageToken'] = 'p'.($index + 2);
            }

            return Http::response($body);
        });

        $r = $this->catalog()->load($this->id);

        $this->assertSame(CatalogStatus::Failed, $r->status);
        $this->assertNull($r->channel);
        $this->assertSame(2, $this->playlistRequests());
        $this->assertSame(0, DB::table('channel_caches')->count());
        $this->assertSame(0, DB::table('channel_cache_videos')->count());
    }

    public function test_api_error_keeps_existing_cache(): void
    {
        $before = $this->importThenAdvance();
        $this->fakeYouTubeError('playlistItems', 500, 'backendError');

        $r = $this->catalog()->load($this->id);

        $this->assertSame(CatalogStatus::Failed, $r->status);
        $this->assertSame($this->id, $r->channel->channel_id);
        $this->assertSame(20, DB::table('channel_cache_videos')->count());
        $this->assertTrue(ChannelCache::find($this->id)->last_synced_at->equalTo($before->last_synced_at));
    }
}

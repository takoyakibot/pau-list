<?php

namespace Tests\Feature;

use App\Services\YouTube\QuotaLedger;
use App\Services\YouTube\ReadBudgetExceeded;
use App\Services\YouTube\YouTubeApiException;
use App\Services\YouTube\YouTubeErrorKind;
use App\Services\YouTube\YouTubeReader;
use App\Support\ChannelRef;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Support\FakesYouTube;
use Tests\TestCase;

class YouTubeReaderTest extends TestCase
{
    use FakesYouTube, RefreshDatabase;

    private function cid(): string
    {
        return 'UC'.str_repeat('a', 22);
    }

    public function test_sends_key_in_header_not_query_and_records_read_unit(): void
    {
        config(['youtube.api_key' => 'secret-key']);
        $this->fakeChannel($this->cid());
        $info = app(YouTubeReader::class)->channel(ChannelRef::id($this->cid()));
        $this->assertSame('UU'.str_repeat('a', 22), $info->uploadsPlaylistId);
        $this->assertSame('https://yt3.ggpht.com/'.$this->cid(), $info->thumbnailUrl);
        Http::assertSent(fn ($r) => $r->hasHeader('X-Goog-Api-Key', 'secret-key') && ! str_contains($r->url(), 'secret-key') && ! str_contains($r->url(), 'key='));
        $this->assertSame(1, app(QuotaLedger::class)->used(QuotaLedger::READ));
    }

    public function test_handle_lookup_uses_for_handle(): void
    {
        $this->fakeChannel($this->cid(), ['handle' => '@Foo']);
        $info = app(YouTubeReader::class)->channel(ChannelRef::handle('foo'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'forHandle=%40foo'));
        $this->assertSame('foo', $info->handle);
    }

    public function test_missing_channel_returns_null(): void
    {
        $this->fakeChannel($this->cid(), ['missing' => true]);
        $this->assertNull(app(YouTubeReader::class)->channel(ChannelRef::id($this->cid())));
    }

    public function test_non_https_ytimg_channel_thumbnail_is_dropped(): void
    {
        $this->fakeChannel($this->cid(), ['thumbnail' => 'https://evil.example/x.jpg']);
        $this->assertNull(app(YouTubeReader::class)->channel(ChannelRef::id($this->cid()))->thumbnailUrl);
    }

    public function test_budget_exhausted_throws_without_sending(): void
    {
        config(['youtube.quota.read_budget' => 0]);
        Http::fake();
        $this->expectException(ReadBudgetExceeded::class);
        try {
            app(YouTubeReader::class)->videos(['dQw4w9WgXcQ']);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_videos_chunks_by_50_and_maps_fields(): void
    {
        $ids = [];
        $videos = [];
        for ($i = 0; $i < 51; $i++) {
            $id = sprintf('vid%08d', $i);
            $ids[] = $id;
            $videos[] = $this->videoResource($id, $this->cid(), ['duration' => 'PT1M5S']);
        }
        $videos[1] = $this->videoResource($ids[1], $this->cid(), ['thumbnail' => 'https://evil.example/a.jpg', 'privacy' => 'private']);
        $this->fakeVideos($videos);

        $result = app(YouTubeReader::class)->videos(array_merge($ids, [$ids[0], 'missing0000']));

        $this->assertCount(51, $result);
        $this->assertArrayNotHasKey('missing0000', $result);
        Http::assertSentCount(2);
        $this->assertSame(2, app(QuotaLedger::class)->used(QuotaLedger::READ));
        $this->assertSame(65, $result[$ids[0]]->durationSeconds);
        $this->assertSame($this->cid(), $result[$ids[0]]->channelId);
        $this->assertTrue($result[$ids[0]]->isPublic());
        $this->assertNull($result[$ids[1]]->thumbnailUrl);
        $this->assertSame('private', $result[$ids[1]]->privacyStatus);
        $this->assertFalse($result[$ids[1]]->isPublic());
        $this->assertSame('2025-01-02T03:04:05+00:00', $result[$ids[0]]->publishedAt->toIso8601String());
    }

    public function test_videos_with_other_bucket_records_that_bucket_and_ignores_read_budget(): void
    {
        config(['youtube.quota.read_budget' => 0]);
        $this->fakeVideos([$this->videoResource('dQw4w9WgXcQ', $this->cid())]);
        $result = app(YouTubeReader::class)->videos(['dQw4w9WgXcQ'], QuotaLedger::OWNER_READ);
        $this->assertCount(1, $result);
        $this->assertSame(1, app(QuotaLedger::class)->used(QuotaLedger::OWNER_READ));
        $this->assertSame(0, app(QuotaLedger::class)->used(QuotaLedger::READ));
    }

    public function test_uploads_page_maps_items_and_next_token(): void
    {
        $pl = 'UU'.str_repeat('a', 22);
        $this->fakeUploads($pl, [
            [$this->uploadItem('aaaaaaaaaaa', '2025-03-01T00:00:00Z'), $this->uploadItem('bbbbbbbbbbb', '2025-02-01T00:00:00Z', 'private', 'T')],
            [$this->uploadItem('ccccccccccc', '2025-01-01T00:00:00Z')],
        ]);
        $reader = app(YouTubeReader::class);
        $page = $reader->uploadsPage($pl, null);
        $this->assertSame('p2', $page->nextPageToken);
        $this->assertSame('aaaaaaaaaaa', $page->items[0]->videoId);
        $this->assertSame('2025-03-01T00:00:00+00:00', $page->items[0]->publishedAt->toIso8601String());
        $this->assertSame('private', $page->items[1]->privacyStatus);
        $last = $reader->uploadsPage($pl, 'p2');
        $this->assertNull($last->nextPageToken);
        $this->assertCount(1, $last->items);
    }

    public function test_uploads_page_treats_playlist_not_found_as_empty(): void
    {
        $this->fakeUploads('UU'.str_repeat('a', 22), []);
        $page = app(YouTubeReader::class)->uploadsPage('UU'.str_repeat('a', 22), null);
        $this->assertSame([], $page->items);
        $this->assertNull($page->nextPageToken);
    }

    public function test_api_error_throws_classified_exception_and_logs_without_key(): void
    {
        config(['youtube.api_key' => 'secret-key']);
        Log::spy();
        $this->fakeYouTubeError('videos', 403, 'quotaExceeded');
        try {
            app(YouTubeReader::class)->videos(['dQw4w9WgXcQ']);
            $this->fail();
        } catch (YouTubeApiException $e) {
            $this->assertSame(YouTubeErrorKind::GoogleQuota, $e->error->kind);
            $this->assertStringNotContainsString('secret-key', $e->getMessage());
        }
        Log::shouldHaveReceived('warning')->withArgs(fn ($m, $ctx) => ! str_contains(json_encode($ctx), 'secret-key') && ($ctx['reason'] ?? null) === 'quotaExceeded');
    }

    public function test_connection_failure_is_transient(): void
    {
        Http::fake(fn () => throw new ConnectionException('x'));
        try {
            app(YouTubeReader::class)->videos(['dQw4w9WgXcQ']);
            $this->fail();
        } catch (YouTubeApiException $e) {
            $this->assertSame(YouTubeErrorKind::Transient, $e->error->kind);
        }
    }

    public function test_malformed_success_body_is_rejected(): void
    {
        Http::fake(fn () => Http::response('not json', 200));
        try {
            app(YouTubeReader::class)->videos(['dQw4w9WgXcQ']);
            $this->fail();
        } catch (YouTubeApiException $e) {
            $this->assertSame(YouTubeErrorKind::Unknown, $e->error->kind);
        }
    }

    public function test_response_ids_are_validated_and_titles_clipped(): void
    {
        $this->fakeChannel($this->cid(), ['uploads' => 'PLbad']);
        $this->assertNull(app(YouTubeReader::class)->channel(ChannelRef::id($this->cid())));

        $pl = 'UU'.str_repeat('a', 22);
        $this->fakeUploads($pl, [[
            $this->uploadItem('bad id', '2025-03-01T00:00:00Z'),
            $this->uploadItem('aaaaaaaaaaa', 'next monday', 'public', str_repeat('あ', 300)),
        ]]);
        $page = app(YouTubeReader::class)->uploadsPage($pl, null);
        $this->assertCount(1, $page->items);
        $this->assertNull($page->items[0]->publishedAt);
        $this->assertSame(200, mb_strlen($page->items[0]->title));
    }

    public function test_videos_filters_invalid_ids_before_sending(): void
    {
        $this->fakeVideos([$this->videoResource('dQw4w9WgXcQ', $this->cid())]);
        $result = app(YouTubeReader::class)->videos(['dQw4w9WgXcQ,injected000', 'dQw4w9WgXcQ']);
        $this->assertSame(['dQw4w9WgXcQ'], array_keys($result));
        Http::assertSent(fn (Request $r) => ! str_contains($r->url(), 'injected') && $r->hasHeader('X-Goog-Api-Key'));
    }

    public function test_playlist_not_found_is_not_logged(): void
    {
        Log::spy();
        $this->fakeUploads('UU'.str_repeat('a', 22), []);
        app(YouTubeReader::class)->uploadsPage('UU'.str_repeat('a', 22), null);
        Log::shouldNotHaveReceived('warning');
    }

    public function test_values_longer_than_their_columns_are_dropped_or_capped(): void
    {
        $long = 'https://yt3.ggpht.com/'.str_repeat('x', 240);
        $this->fakeChannel($this->cid(), ['thumbnail' => $long, 'handle' => '@'.str_repeat('h', 256)]);
        $info = app(YouTubeReader::class)->channel(ChannelRef::id($this->cid()));
        $this->assertNull($info->thumbnailUrl);
        $this->assertNull($info->handle);

        $ok = 'https://yt3.ggpht.com/'.str_repeat('x', 255 - 22);
        $this->assertSame(255, strlen($ok));
        Http::swap(new Factory);
        $this->fakeChannel($this->cid(), ['thumbnail' => $ok]);
        $this->assertSame($ok, app(YouTubeReader::class)->channel(ChannelRef::id($this->cid()))->thumbnailUrl);

        Http::swap(new Factory);
        $video = $this->videoResource('abcdefghijk', str_repeat('C', 300), [
            'channelTitle' => str_repeat('名', 300),
            'thumbnail' => 'https://i.ytimg.com/'.str_repeat('y', 300),
        ]);
        $this->fakeVideos([$video]);
        $v = app(YouTubeReader::class)->videos(['abcdefghijk'])['abcdefghijk'];
        $this->assertSame('', $v->channelId);
        $this->assertSame(200, mb_strlen($v->channelTitle));
        $this->assertNull($v->thumbnailUrl);

        Http::swap(new Factory);
        $this->fakeUploads('UU'.str_repeat('a', 22), [[
            ['snippet' => ['title' => 't', 'thumbnails' => ['medium' => ['url' => 'https://i.ytimg.com/'.str_repeat('z', 300)]]],
                'contentDetails' => ['videoId' => 'abcdefghijk'], 'status' => ['privacyStatus' => 'public']],
        ]]);
        $this->assertNull(app(YouTubeReader::class)->uploadsPage('UU'.str_repeat('a', 22), null)->items[0]->thumbnailUrl);
    }

    public function test_video_info_parses_one_resource_and_rejects_a_bad_id(): void
    {
        $v = YouTubeReader::videoInfo($this->videoResource('aaaaaaaaaaa', 'UC'.str_repeat('a', 22), ['privacy' => 'unlisted', 'duration' => 'PT2M']));
        $this->assertSame('aaaaaaaaaaa', $v->id);
        $this->assertSame('unlisted', $v->privacyStatus);
        $this->assertSame(120, $v->durationSeconds);
        $this->assertNull(YouTubeReader::videoInfo($this->videoResource('short', 'UC'.str_repeat('a', 22))));
        $this->assertNull(YouTubeReader::videoInfo(['snippet' => []]));
    }
}

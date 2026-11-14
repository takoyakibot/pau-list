<?php

namespace Tests\Feature;

use App\Services\YouTube\QuotaLedger;
use App\Services\YouTube\YouTubeApiException;
use App\Services\YouTube\YouTubeErrorKind;
use App\Services\YouTube\YouTubeOwnerClient;
use App\Services\YouTube\YouTubeReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakesGoogle;
use Tests\Support\FakesYouTube;
use Tests\TestCase;

class YouTubeOwnerClientTest extends TestCase
{
    use FakesGoogle, FakesYouTube, RefreshDatabase;

    /** @var list<string> */
    private array $logged = [];

    private function captureLogs(): void
    {
        Event::listen(MessageLogged::class, function (MessageLogged $e) {
            $this->logged[] = $e->message.' '.json_encode($e->context);
        });
    }

    private function expectKind(YouTubeErrorKind $kind): YouTubeApiException
    {
        try {
            app(YouTubeOwnerClient::class)->myChannel('ya29.tok');
        } catch (YouTubeApiException $e) {
            $this->assertSame($kind, $e->error->kind);

            return $e;
        }
        $this->fail('YouTubeApiException was not thrown');
    }

    public function test_my_channel_uses_bearer_and_records_owner_read(): void
    {
        config(['youtube.api_key' => 'secret-api-key']);
        $this->fakeMyChannel('UC'.str_repeat('a', 22), '<b>私のチャンネル</b>');
        $ch = app(YouTubeOwnerClient::class)->myChannel('ya29.tok');
        $this->assertSame('UC'.str_repeat('a', 22), $ch->id);
        $this->assertSame('<b>私のチャンネル</b>', $ch->title);
        $req = Http::recorded()->sole()[0];
        $this->assertSame('Bearer ya29.tok', $req->header('Authorization')[0]);
        $this->assertFalse($req->hasHeader('X-Goog-Api-Key'));
        $this->assertStringNotContainsString('secret-api-key', $req->url());
        $this->assertStringStartsWith(YouTubeReader::BASE_URL.'channels?', $req->url());
        parse_str(parse_url($req->url(), PHP_URL_QUERY), $q);
        $this->assertSame(['part' => 'snippet', 'mine' => 'true'], $q);
        $this->assertSame(1, app(QuotaLedger::class)->used(QuotaLedger::OWNER_READ));
        $this->assertSame(0, app(QuotaLedger::class)->used(QuotaLedger::READ));
    }

    public function test_title_is_cut_at_200_chars(): void
    {
        $this->fakeMyChannel('UC'.str_repeat('a', 22), str_repeat('あ', 250));
        $this->assertSame(str_repeat('あ', 200), app(YouTubeOwnerClient::class)->myChannel('ya29.tok')->title);
    }

    public function test_my_channel_returns_null_without_channel(): void
    {
        $this->fakeMyChannel(null);
        $this->assertNull(app(YouTubeOwnerClient::class)->myChannel('ya29.tok'));
        $this->assertSame(1, app(QuotaLedger::class)->used(QuotaLedger::OWNER_READ));
    }

    public function test_my_channel_returns_null_for_malformed_id(): void
    {
        $this->fakeMyChannel('UCshort');
        $this->assertNull(app(YouTubeOwnerClient::class)->myChannel('ya29.tok'));
    }

    public function test_non_array_items_is_malformed_error(): void
    {
        Http::fake(fn () => Http::response(['items' => 'x']));
        $this->expectKind(YouTubeErrorKind::Unknown);
    }

    public function test_my_channel_401_is_auth_error_without_token_in_log(): void
    {
        $this->captureLogs();
        Http::fake(fn () => Http::response(['error' => ['code' => 401, 'message' => 'ya29.tok invalid', 'errors' => [['reason' => 'authError']]]], 401));
        $e = $this->expectKind(YouTubeErrorKind::Auth);
        $this->assertSame(401, $e->error->status);
        $this->assertNotEmpty($this->logged);
        foreach ($this->logged as $line) {
            $this->assertStringNotContainsString('ya29', $line);
            $this->assertStringContainsString('authError', $line);
        }
        $this->assertStringNotContainsString('ya29', $e->getMessage());
    }

    public function test_connection_error_is_transient(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28 ya29.tok'));
        $this->captureLogs();
        $e = $this->expectKind(YouTubeErrorKind::Transient);
        foreach ($this->logged as $line) {
            $this->assertStringNotContainsString('ya29', $line);
        }
        $this->assertStringNotContainsString('ya29', $e->getMessage());
    }

    public function test_redirect_is_not_followed(): void
    {
        Http::fake(fn () => Http::response('', 302, ['Location' => 'https://evil.example/']));
        $this->expectException(YouTubeApiException::class);
        app(YouTubeOwnerClient::class)->myChannel('ya29.tok');
    }

    public function test_read_budget_exhaustion_does_not_block_owner_read(): void
    {
        config(['youtube.quota.read_budget' => 0]);
        $this->fakeMyChannel('UC'.str_repeat('a', 22));
        $this->assertNotNull(app(YouTubeOwnerClient::class)->myChannel('ya29.tok'));
    }

    public function test_token_argument_is_sensitive(): void
    {
        $param = (new \ReflectionMethod(YouTubeOwnerClient::class, 'myChannel'))->getParameters()[0];
        $this->assertNotEmpty($param->getAttributes(\SensitiveParameter::class));
    }

    private const OWNER = 'UCaaaaaaaaaaaaaaaaaaaaaa';

    private const UPLOADS = 'UUaaaaaaaaaaaaaaaaaaaaaa';

    public function test_unlisted_uploads_reads_own_uploads_with_bearer_and_keeps_only_unlisted(): void
    {
        config(['youtube.api_key' => 'secret-api-key']);
        $this->fakeUploads(self::UPLOADS, [
            [
                $this->uploadItem('pub00000001', '2026-11-01T00:00:00Z'),
                $this->uploadItem('unl00000001', '2026-11-02T00:00:00Z', 'unlisted', '<b>限定 1</b>'),
                $this->uploadItem('prv00000001', '2026-11-03T00:00:00Z', 'private'),
            ],
            [$this->uploadItem('unl00000002', '2026-10-01T00:00:00Z', 'unlisted')],
        ]);
        $items = app(YouTubeOwnerClient::class)->unlistedUploads('ya29.tok', self::OWNER);
        $this->assertSame(['unl00000001', 'unl00000002'], array_map(fn ($i) => $i->videoId, $items));
        $this->assertSame('<b>限定 1</b>', $items[0]->title);
        $this->assertSame('https://i.ytimg.com/vi/unl00000001/mqdefault.jpg', $items[0]->thumbnailUrl);
        $this->assertSame('2026-11-02T00:00:00Z', $items[0]->publishedAt->utc()->format('Y-m-d\TH:i:s\Z'));

        $requests = Http::recorded()->map(fn ($pair) => $pair[0]);
        $this->assertCount(2, $requests);   // no channels.list: the uploads id is derived from the channel id
        foreach ($requests as $req) {
            $this->assertSame('Bearer ya29.tok', $req->header('Authorization')[0]);
            $this->assertFalse($req->hasHeader('X-Goog-Api-Key'));
            $this->assertStringNotContainsString('secret-api-key', $req->url());
        }
        parse_str(parse_url($requests[0]->url(), PHP_URL_QUERY), $q);
        $this->assertSame(['part' => 'snippet,contentDetails,status', 'maxResults' => '50', 'playlistId' => self::UPLOADS], $q);
        $this->assertSame(2, app(QuotaLedger::class)->used(QuotaLedger::OWNER_READ));
        $this->assertSame(0, app(QuotaLedger::class)->used(QuotaLedger::READ));
    }

    public function test_unlisted_uploads_paging_is_bounded_by_the_fetch_cap(): void
    {
        config(['youtube.channel.fetch_max_videos' => 100]);
        $pages = [];
        for ($p = 0; $p < 5; $p++) {
            $pages[] = [$this->uploadItem(sprintf('unl%08d', $p), '2026-11-01T00:00:00Z', 'unlisted')];
        }
        $this->fakeUploads(self::UPLOADS, $pages);
        $items = app(YouTubeOwnerClient::class)->unlistedUploads('ya29.tok', self::OWNER);
        $this->assertCount(2, $items);
        $this->assertCount(2, $this->googleRequests(YouTubeReader::BASE_URL.'playlistItems?'));
    }

    public function test_unlisted_uploads_is_empty_for_a_channel_without_uploads(): void
    {
        $this->fakeUploads(self::UPLOADS, []);
        $this->assertSame([], app(YouTubeOwnerClient::class)->unlistedUploads('ya29.tok', self::OWNER));
    }

    public function test_unlisted_uploads_rejects_a_malformed_channel_id_without_calling(): void
    {
        foreach (['UCshort', 'UU'.str_repeat('a', 22), 'UC'.str_repeat('a', 21).'!'] as $id) {
            $this->assertSame([], app(YouTubeOwnerClient::class)->unlistedUploads('ya29.tok', $id));
        }
        $this->assertCount(0, Http::recorded());
    }

    public function test_unlisted_uploads_error_mid_paging_throws_without_token_in_log(): void
    {
        $this->captureLogs();
        Http::fake(function ($request) {
            if (! str_starts_with($request->url(), YouTubeReader::BASE_URL.'playlistItems?')) {
                return null;
            }
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $q);

            return isset($q['pageToken'])
                ? Http::response(['error' => ['code' => 500, 'message' => 'ya29.tok', 'errors' => [['reason' => 'backendError']]]], 500)
                : Http::response(['items' => [$this->uploadItem('unl00000001', '2026-11-01T00:00:00Z', 'unlisted')], 'nextPageToken' => 'p2']);
        });
        try {
            app(YouTubeOwnerClient::class)->unlistedUploads('ya29.tok', self::OWNER);
            $this->fail('YouTubeApiException was not thrown');
        } catch (YouTubeApiException $e) {
            $this->assertSame(500, $e->error->status);
        }
        foreach ($this->logged as $line) {
            $this->assertStringNotContainsString('ya29', $line);
        }
    }

    public function test_unlisted_uploads_token_argument_is_sensitive(): void
    {
        $param = (new \ReflectionMethod(YouTubeOwnerClient::class, 'unlistedUploads'))->getParameters()[0];
        $this->assertNotEmpty($param->getAttributes(\SensitiveParameter::class));
    }

    public function test_insert_playlist_posts_snippet_and_status_without_recording_quota(): void
    {
        config(['youtube.api_key' => 'secret-api-key']);
        $this->fakePlaylistInsert('PLabc');
        $id = app(YouTubeOwnerClient::class)->insertPlaylist('ya29.tok', '名前<>', '説明', 'unlisted');
        $this->assertSame('PLabc', $id);
        $req = Http::recorded()->sole()[0];
        $this->assertSame('POST', $req->method());
        $this->assertSame('Bearer ya29.tok', $req->header('Authorization')[0]);
        $this->assertFalse($req->hasHeader('X-Goog-Api-Key'));
        $this->assertStringContainsString('application/json', $req->header('Content-Type')[0]);
        $this->assertStringStartsWith(YouTubeReader::BASE_URL.'playlists?', $req->url());
        parse_str(parse_url($req->url(), PHP_URL_QUERY), $q);
        $this->assertSame(['part' => 'snippet,status'], $q);
        $this->assertSame(['snippet' => ['title' => '名前<>', 'description' => '説明'], 'status' => ['privacyStatus' => 'unlisted']], $req->data());
        $this->assertSame(0, app(QuotaLedger::class)->used(QuotaLedger::WRITE));
        $this->assertSame(0, app(QuotaLedger::class)->used(QuotaLedger::OWNER_READ));
    }

    public function test_insert_playlist_rejects_bad_input_before_any_request(): void
    {
        $this->fakePlaylistInsert('PLabc');
        $client = app(YouTubeOwnerClient::class);
        $cases = [
            ['t', '', 'friends'],
            ['t', '', 'Private'],
            ['', '', 'private'],
            [str_repeat('あ', 151), '', 'private'],
            ['t', str_repeat('い', 1001), 'private'],
        ];
        foreach ($cases as [$title, $description, $privacy]) {
            try {
                $client->insertPlaylist('ya29.tok', $title, $description, $privacy);
                $this->fail('InvalidArgumentException was not thrown for '.json_encode([$title, $privacy]));
            } catch (\InvalidArgumentException) {
            }
        }
        $this->assertCount(0, Http::recorded());
        $this->assertSame('PLabc', $client->insertPlaylist('ya29.tok', str_repeat('あ', 150), str_repeat('い', 1000), 'public'));
    }

    public function test_insert_playlist_with_malformed_id_is_malformed_error(): void
    {
        foreach (['', 'P', 'PL bad', str_repeat('a', 65)] as $bad) {
            Http::swap(new Factory);
            Http::preventStrayRequests();
            $this->fakePlaylistInsert($bad);
            try {
                app(YouTubeOwnerClient::class)->insertPlaylist('ya29.tok', 't', '', 'private');
                $this->fail('YouTubeApiException was not thrown for '.json_encode($bad));
            } catch (YouTubeApiException $e) {
                $this->assertSame(YouTubeErrorKind::Unknown, $e->error->kind);
                $this->assertSame('malformedResponse', $e->error->reason);
            }
        }
    }

    public function test_insert_item_sends_position_and_resource_id(): void
    {
        $this->fakePlaylistItemInsert(['UExhYmMuMDE=']);
        $id = app(YouTubeOwnerClient::class)->insertPlaylistItem('ya29.tok', 'PLabc', 'aaaaaaaaaaa', 3);
        $this->assertSame('UExhYmMuMDE=', $id);
        $req = Http::recorded()->sole()[0];
        $this->assertSame('POST', $req->method());
        $this->assertSame('Bearer ya29.tok', $req->header('Authorization')[0]);
        parse_str(parse_url($req->url(), PHP_URL_QUERY), $q);
        $this->assertSame(['part' => 'snippet'], $q);
        $this->assertSame(['snippet' => ['playlistId' => 'PLabc', 'position' => 3, 'resourceId' => ['kind' => 'youtube#video', 'videoId' => 'aaaaaaaaaaa']]], $req->data());
        $this->assertSame(0, app(QuotaLedger::class)->used(QuotaLedger::WRITE));
        $this->assertSame(0, app(QuotaLedger::class)->used(QuotaLedger::OWNER_READ));
    }

    public function test_insert_item_with_malformed_id_is_malformed_error(): void
    {
        $this->fakePlaylistItemInsert([Http::response(['id' => 'bad id!'])]);
        $this->expectException(YouTubeApiException::class);
        app(YouTubeOwnerClient::class)->insertPlaylistItem('ya29.tok', 'PLabc', 'aaaaaaaaaaa', 0);
    }

    /** @return array<string, array{int, ?string, YouTubeErrorKind}> */
    public static function errors(): array
    {
        return [
            '500' => [500, null, YouTubeErrorKind::Transient],
            '409' => [409, null, YouTubeErrorKind::Transient],
            '403 quotaExceeded' => [403, 'quotaExceeded', YouTubeErrorKind::GoogleQuota],
            '401' => [401, null, YouTubeErrorKind::Auth],
            '404 videoNotFound' => [404, 'videoNotFound', YouTubeErrorKind::ItemPermanent],
            '400 invalidValue' => [400, 'invalidValue', YouTubeErrorKind::InvalidInput],
        ];
    }

    #[DataProvider('errors')]
    public function test_write_errors_are_classified(int $status, ?string $reason, YouTubeErrorKind $kind): void
    {
        $this->fakePlaylistInsert('PLx', $status, $reason);
        $this->fakePlaylistItemInsert([[$status, $reason]]);
        $client = app(YouTubeOwnerClient::class);
        foreach ([
            fn () => $client->insertPlaylist('ya29.tok', 't', 'd', 'private'),
            fn () => $client->insertPlaylistItem('ya29.tok', 'PLabc', 'aaaaaaaaaaa', 0),
        ] as $call) {
            try {
                $call();
                $this->fail('YouTubeApiException was not thrown');
            } catch (YouTubeApiException $e) {
                $this->assertSame($kind, $e->error->kind);
                $this->assertSame($status, $e->error->status);
                $this->assertSame($reason, $e->error->reason);
            }
        }
        $this->assertSame(0, app(QuotaLedger::class)->used(QuotaLedger::WRITE));
    }

    public function test_connection_error_on_write_is_transient_with_null_status(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28 ya29.tok'));
        $this->captureLogs();
        try {
            app(YouTubeOwnerClient::class)->insertPlaylistItem('ya29.tok', 'PLabc', 'aaaaaaaaaaa', 0);
            $this->fail('YouTubeApiException was not thrown');
        } catch (YouTubeApiException $e) {
            $this->assertSame(YouTubeErrorKind::Transient, $e->error->kind);
            $this->assertNull($e->error->status);
            $this->assertStringNotContainsString('ya29', $e->getMessage());
        }
        foreach ($this->logged as $line) {
            $this->assertStringNotContainsString('ya29', $line);
        }
    }

    public function test_redirect_on_write_is_not_followed(): void
    {
        Http::fake(fn () => Http::response('', 302, ['Location' => 'https://evil.example/']));
        $this->expectException(YouTubeApiException::class);
        app(YouTubeOwnerClient::class)->insertPlaylist('ya29.tok', 't', 'd', 'private');
    }

    public function test_my_playlists_reads_one_page_and_records_owner_read(): void
    {
        config(['youtube.api_key' => 'secret-api-key']);
        $this->fakeMyPlaylists([
            ['id' => 'PLone', 'title' => '<b>一つ目</b>', 'publishedAt' => '2026-11-15T01:02:03Z', 'description' => "説明\n2 行目"],
            ['id' => 'PL two', 'title' => 'bad id'],
            ['id' => 'PLthree', 'title' => str_repeat('あ', 250), 'publishedAt' => null],
        ], true);
        $page = app(YouTubeOwnerClient::class)->myPlaylists('ya29.tok');
        $this->assertTrue($page->hasMore);
        $this->assertSame(['PLone', 'PLthree'], array_map(fn ($p) => $p->id, $page->items));
        $this->assertSame('<b>一つ目</b>', $page->items[0]->title);
        $this->assertSame('2026-11-15T01:02:03Z', $page->items[0]->publishedAt->utc()->format('Y-m-d\TH:i:s\Z'));
        $this->assertSame(str_repeat('あ', 200), $page->items[1]->title);
        $this->assertNull($page->items[1]->publishedAt);
        $this->assertSame(["説明\n2 行目", ''], array_map(fn ($p) => $p->description, $page->items));

        $req = Http::recorded()->sole()[0];
        $this->assertSame('GET', $req->method());
        $this->assertSame('Bearer ya29.tok', $req->header('Authorization')[0]);
        $this->assertFalse($req->hasHeader('X-Goog-Api-Key'));
        parse_str(parse_url($req->url(), PHP_URL_QUERY), $q);
        $this->assertSame(['part' => 'snippet', 'mine' => 'true', 'maxResults' => '50'], $q);
        $this->assertSame(1, app(QuotaLedger::class)->used(QuotaLedger::OWNER_READ));

        Http::swap(new Factory);
        Http::preventStrayRequests();
        $this->fakeMyPlaylists([]);
        $this->assertFalse(app(YouTubeOwnerClient::class)->myPlaylists('ya29.tok')->hasMore);
    }

    public function test_playlist_video_items_pages_up_to_max(): void
    {
        $map = [];
        for ($i = 0; $i < 120; $i++) {
            $map[sprintf('v%010d', $i)] = 'item'.$i;
        }
        $this->fakePlaylistItemsList($map);
        $client = app(YouTubeOwnerClient::class);

        $found = $client->playlistVideoItems('ya29.tok', 'PLabc', 2, $truncated);
        $this->assertTrue($truncated);
        $this->assertCount(100, $found);
        $this->assertSame('item0', $found['v0000000000']);
        $this->assertSame('item99', $found['v0000000099']);
        $this->assertArrayNotHasKey('v0000000100', $found);
        $this->assertSame(2, app(QuotaLedger::class)->used(QuotaLedger::OWNER_READ));

        $requests = $this->googleRequests(YouTubeReader::BASE_URL.'playlistItems?');
        $this->assertCount(2, $requests);
        parse_str(parse_url($requests[0]->url(), PHP_URL_QUERY), $q);
        $this->assertSame(['part' => 'snippet', 'maxResults' => '50', 'playlistId' => 'PLabc'], $q);
        $this->assertSame('Bearer ya29.tok', $requests[1]->header('Authorization')[0]);
        $this->assertCount(120, $client->playlistVideoItems('ya29.tok', 'PLabc', 5, $truncated));
        $this->assertFalse($truncated);
        $this->assertSame(5, app(QuotaLedger::class)->used(QuotaLedger::OWNER_READ));
    }

    public function test_playlist_video_items_keeps_the_first_item_of_a_duplicate_video(): void
    {
        Http::fake(fn () => Http::response(['items' => [
            ['id' => 'first', 'snippet' => ['resourceId' => ['kind' => 'youtube#video', 'videoId' => 'aaaaaaaaaaa']]],
            ['id' => 'second', 'snippet' => ['resourceId' => ['kind' => 'youtube#video', 'videoId' => 'aaaaaaaaaaa']]],
            ['id' => 'bad id!', 'snippet' => ['resourceId' => ['kind' => 'youtube#video', 'videoId' => 'bbbbbbbbbbb']]],
            ['id' => 'short', 'snippet' => ['resourceId' => ['kind' => 'youtube#video', 'videoId' => 'short']]],
        ]]));
        $this->assertSame(['aaaaaaaaaaa' => 'first'], app(YouTubeOwnerClient::class)->playlistVideoItems('ya29.tok', 'PLabc', 3));
    }

    public function test_owner_videos_use_token_and_parse_like_reader(): void
    {
        config(['youtube.api_key' => 'secret-api-key']);
        $resources = [];
        for ($i = 0; $i < 51; $i++) {
            $resources[] = $this->videoResource(sprintf('v%010d', $i), self::OWNER, ['privacy' => $i === 0 ? 'unlisted' : 'public', 'title' => '<i>T'.$i.'</i>', 'duration' => 'PT1M5S']);
        }
        $this->fakeOwnerVideos($resources);
        $ids = array_map(fn ($r) => $r['id'], $resources);
        $videos = app(YouTubeOwnerClient::class)->videos('ya29.tok', [...$ids, 'bad', $ids[0]]);
        $this->assertCount(51, $videos);
        $v = $videos['v0000000000'];
        $this->assertSame('unlisted', $v->privacyStatus);
        $this->assertSame('<i>T0</i>', $v->title);
        $this->assertSame(65, $v->durationSeconds);
        $this->assertSame(self::OWNER, $v->channelId);
        $this->assertSame('https://i.ytimg.com/vi/v0000000000/mqdefault.jpg', $v->thumbnailUrl);

        $requests = $this->googleRequests(YouTubeReader::BASE_URL.'videos?');
        $this->assertCount(2, $requests);
        foreach ($requests as $req) {
            $this->assertSame('Bearer ya29.tok', $req->header('Authorization')[0]);
            $this->assertFalse($req->hasHeader('X-Goog-Api-Key'));
            $this->assertStringNotContainsString('secret-api-key', $req->url());
        }
        parse_str(parse_url($requests[0]->url(), PHP_URL_QUERY), $q);
        $this->assertSame('snippet,contentDetails,status', $q['part']);
        $this->assertCount(50, explode(',', $q['id']));
        $this->assertSame(2, app(QuotaLedger::class)->used(QuotaLedger::OWNER_READ));
        $this->assertSame(0, app(QuotaLedger::class)->used(QuotaLedger::READ));
    }

    public function test_write_failure_log_has_no_token_or_body(): void
    {
        $this->captureLogs();
        Http::fake(fn () => Http::response(['error' => ['code' => 400, 'message' => 'ya29.tok 秘密の名前', 'errors' => [['reason' => 'invalidValue']]]], 400));
        try {
            app(YouTubeOwnerClient::class)->insertPlaylist('ya29.tok', '秘密の名前', '秘密の説明', 'private');
            $this->fail('YouTubeApiException was not thrown');
        } catch (YouTubeApiException $e) {
            $this->assertStringNotContainsString('ya29', $e->getMessage());
            $this->assertStringNotContainsString('秘密', $e->getMessage());
        }
        $this->assertNotEmpty($this->logged);
        foreach ($this->logged as $line) {
            $this->assertStringNotContainsString('ya29', $line);
            $this->assertStringNotContainsString('秘密', $line);
            $this->assertStringContainsString('invalidValue', $line);
        }
    }

    public function test_write_and_lookup_token_arguments_are_sensitive(): void
    {
        foreach (['insertPlaylist', 'insertPlaylistItem', 'myPlaylists', 'playlistVideoItems', 'videos'] as $method) {
            $param = (new \ReflectionMethod(YouTubeOwnerClient::class, $method))->getParameters()[0];
            $this->assertNotEmpty($param->getAttributes(\SensitiveParameter::class), $method);
        }
    }
}

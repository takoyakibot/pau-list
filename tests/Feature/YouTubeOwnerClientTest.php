<?php

namespace Tests\Feature;

use App\Services\YouTube\QuotaLedger;
use App\Services\YouTube\YouTubeApiException;
use App\Services\YouTube\YouTubeErrorKind;
use App\Services\YouTube\YouTubeOwnerClient;
use App\Services\YouTube\YouTubeReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
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
        $this->fakeChannel(self::OWNER);
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
        $this->assertCount(3, $requests);
        foreach ($requests as $req) {
            $this->assertSame('Bearer ya29.tok', $req->header('Authorization')[0]);
            $this->assertFalse($req->hasHeader('X-Goog-Api-Key'));
            $this->assertStringNotContainsString('secret-api-key', $req->url());
        }
        parse_str(parse_url($requests[0]->url(), PHP_URL_QUERY), $q);
        $this->assertSame(['part' => 'contentDetails', 'mine' => 'true'], $q);
        parse_str(parse_url($requests[1]->url(), PHP_URL_QUERY), $q);
        $this->assertSame(['part' => 'snippet,contentDetails,status', 'maxResults' => '50', 'playlistId' => self::UPLOADS], $q);
        $this->assertSame(3, app(QuotaLedger::class)->used(QuotaLedger::OWNER_READ));
        $this->assertSame(0, app(QuotaLedger::class)->used(QuotaLedger::READ));
    }

    public function test_unlisted_uploads_paging_is_bounded_by_the_fetch_cap(): void
    {
        config(['youtube.channel.fetch_max_videos' => 100]);
        $this->fakeChannel(self::OWNER);
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
        $this->fakeChannel(self::OWNER);
        $this->fakeUploads(self::UPLOADS, []);
        $this->assertSame([], app(YouTubeOwnerClient::class)->unlistedUploads('ya29.tok', self::OWNER));
    }

    public function test_unlisted_uploads_is_empty_when_the_token_belongs_to_another_channel(): void
    {
        $this->fakeChannel('UC'.str_repeat('z', 22));
        $this->assertSame([], app(YouTubeOwnerClient::class)->unlistedUploads('ya29.tok', self::OWNER));
        $this->assertCount(0, $this->googleRequests(YouTubeReader::BASE_URL.'playlistItems?'));
    }

    public function test_unlisted_uploads_error_mid_paging_throws_without_token_in_log(): void
    {
        $this->captureLogs();
        $this->fakeChannel(self::OWNER);
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
}

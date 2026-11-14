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
use Tests\TestCase;

class YouTubeOwnerClientTest extends TestCase
{
    use FakesGoogle, RefreshDatabase;

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
}

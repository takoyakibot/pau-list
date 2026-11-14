<?php

namespace Tests\Feature;

use App\Services\YouTube\GoogleOAuth;
use App\Services\YouTube\OAuthFailed;
use Carbon\CarbonImmutable;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakesGoogle;
use Tests\TestCase;

class GoogleOAuthTest extends TestCase
{
    use FakesGoogle;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.google' => [
            'client_id' => 'cid.apps.googleusercontent.com',
            'client_secret' => 'test-client-secret',
            'redirect' => 'http://localhost:8000/auth/google/callback',
        ]]);
    }

    /** @var list<string> */
    private array $logged = [];

    private function captureLogs(): void
    {
        Event::listen(MessageLogged::class, function (MessageLogged $e) {
            $this->logged[] = $e->message.' '.json_encode($e->context);
        });
    }

    private function expectReason(string $reason, callable $call): void
    {
        try {
            $call();
            $this->fail('OAuthFailed was not thrown');
        } catch (OAuthFailed $e) {
            $this->assertSame($reason, $e->reason);
            $this->assertSame('Google OAuth token request failed: '.$reason, $e->getMessage());
        }
    }

    public function test_authorization_url_parameters(): void
    {
        $url = app(GoogleOAuth::class)->authorizationUrl('STATE_x', 'CHALLENGE_y');
        $this->assertStringStartsWith(GoogleOAuth::AUTHORIZE_URL.'?', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        $expected = [
            'client_id' => 'cid.apps.googleusercontent.com', 'redirect_uri' => 'http://localhost:8000/auth/google/callback',
            'response_type' => 'code', 'scope' => GoogleOAuth::SCOPE, 'access_type' => 'online',
            'include_granted_scopes' => 'false', 'prompt' => 'select_account', 'state' => 'STATE_x',
            'code_challenge' => 'CHALLENGE_y', 'code_challenge_method' => 'S256',
        ];
        ksort($expected);
        ksort($q);
        $this->assertSame($expected, $q);
        $this->assertStringNotContainsString('test-client-secret', $url);
        $this->assertStringContainsString('%3A%2F%2F', $url); // RFC 3986 エンコード
        $this->assertStringNotContainsString('+', $url);
    }

    public function test_exchange_posts_form_body(): void
    {
        $this->fakeToken();
        $token = app(GoogleOAuth::class)->exchangeCode('4/0Acode', 'VERIFIER');
        $req = $this->googleRequests(GoogleOAuth::TOKEN_URL)->sole();
        $this->assertSame('POST', $req->method());
        $this->assertSame(GoogleOAuth::TOKEN_URL, $req->url());
        $this->assertStringContainsString('application/x-www-form-urlencoded', $req->header('Content-Type')[0]);
        $this->assertSame(['code' => '4/0Acode', 'client_id' => 'cid.apps.googleusercontent.com', 'client_secret' => 'test-client-secret',
            'redirect_uri' => 'http://localhost:8000/auth/google/callback', 'grant_type' => 'authorization_code', 'code_verifier' => 'VERIFIER'], $req->data());
        $this->assertSame('ya29.test-access-token', $token->accessToken);
        $this->assertSame([GoogleOAuth::SCOPE], $token->scopes);
        $this->assertTrue($token->hasScope(GoogleOAuth::SCOPE));
    }

    public static function badTokenResponses(): array
    {
        return [
            'no access_token' => [['access_token' => null]], 'empty access_token' => [['access_token' => '']],
            'array access_token' => [['access_token' => ['x']]], 'token_type mac' => [['token_type' => 'mac']],
            'no token_type' => [['token_type' => null]], 'expires_in zero' => [['expires_in' => 0]],
            'expires_in string' => [['expires_in' => 'abc']], 'expires_in missing' => [['expires_in' => null]],
            'expires_in negative' => [['expires_in' => -5]], 'expires_in float' => [['expires_in' => 3599.5]],
            'token_type array' => [['token_type' => ['Bearer']]],
        ];
    }

    #[DataProvider('badTokenResponses')]
    public function test_exchange_rejects_invalid_token_responses(array $override): void
    {
        $this->fakeToken($override);
        $this->expectReason('malformed', fn () => app(GoogleOAuth::class)->exchangeCode('c', 'v'));
    }

    public function test_exchange_rejects_non_json_body(): void
    {
        Http::fake([GoogleOAuth::TOKEN_URL => Http::response('oops', 200)]);
        $this->expectReason('malformed', fn () => app(GoogleOAuth::class)->exchangeCode('c', 'v'));
    }

    public function test_exchange_rejects_non_200(): void
    {
        $this->fakeToken(['error' => 'invalid_grant'], 400);
        $this->expectReason('http_error', fn () => app(GoogleOAuth::class)->exchangeCode('c', 'v'));
    }

    public function test_exchange_rejects_server_error(): void
    {
        Http::fake([GoogleOAuth::TOKEN_URL => Http::response('<html>', 503)]);
        $this->expectReason('http_error', fn () => app(GoogleOAuth::class)->exchangeCode('c', 'v'));
    }

    public function test_token_type_is_case_insensitive(): void
    {
        $this->fakeToken(['token_type' => 'bearer', 'expires_in' => '3599']);
        $this->assertSame('ya29.test-access-token', app(GoogleOAuth::class)->exchangeCode('c', 'v')->accessToken);
    }

    public function test_expires_at_has_60s_margin(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-14 03:00:00'));
        $this->fakeToken(['expires_in' => 3599]);
        $this->assertEquals(now()->addSeconds(3539), app(GoogleOAuth::class)->exchangeCode('c', 'v')->expiresAt);
    }

    public function test_expires_at_is_capped_at_3600(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-14 03:00:00'));
        $this->fakeToken(['expires_in' => 999999]);
        $this->assertEquals(now()->addSeconds(3540), app(GoogleOAuth::class)->exchangeCode('c', 'v')->expiresAt);
    }

    public function test_scope_is_split_on_whitespace_and_may_be_missing(): void
    {
        $this->fakeToken(['scope' => 'openid  '.GoogleOAuth::SCOPE]);
        $token = app(GoogleOAuth::class)->exchangeCode('c', 'v');
        $this->assertSame(['openid', GoogleOAuth::SCOPE], $token->scopes);
        $this->assertTrue($token->hasScope(GoogleOAuth::SCOPE));
        $this->assertFalse($token->hasScope('https://www.googleapis.com/auth/youtube.readonly'));
    }

    public function test_missing_scope_gives_empty_list(): void
    {
        $this->fakeToken(['scope' => null]);
        $token = app(GoogleOAuth::class)->exchangeCode('c', 'v');
        $this->assertSame([], $token->scopes);
        $this->assertFalse($token->hasScope(GoogleOAuth::SCOPE));
    }

    public function test_refresh_token_is_dropped(): void
    {
        $this->fakeToken(['refresh_token' => '1//refresh-secret', 'id_token' => 'eyJ.x.y']);
        $token = app(GoogleOAuth::class)->exchangeCode('c', 'v');
        $this->assertStringNotContainsString('refresh-secret', serialize($token));
        $this->assertStringNotContainsString('eyJ.x.y', serialize($token));
        $this->assertSame(['accessToken', 'expiresAt', 'scopes'], array_keys(get_object_vars($token)));
    }

    public function test_timeout_is_not_retried(): void
    {
        Http::fake([GoogleOAuth::TOKEN_URL => Http::failedConnection('timed out')]);
        $this->expectReason('connection', fn () => app(GoogleOAuth::class)->exchangeCode('c', 'v'));
        Http::assertSentCount(1);
    }

    public function test_invalid_grant_is_not_retried(): void
    {
        $this->fakeToken(['error' => 'invalid_grant'], 400);
        $this->expectReason('http_error', fn () => app(GoogleOAuth::class)->exchangeCode('c', 'v'));
        Http::assertSentCount(1);
    }

    public function test_redirect_response_is_failure(): void
    {
        Http::fake([GoogleOAuth::TOKEN_URL => Http::response('', 302, ['Location' => 'https://evil.example/']),
            GoogleOAuth::REVOKE_URL => Http::response('', 302, ['Location' => 'https://evil.example/'])]);
        $this->expectReason('redirect', fn () => app(GoogleOAuth::class)->exchangeCode('c', 'v'));
        $this->assertFalse(app(GoogleOAuth::class)->revoke('tok'));
        Http::assertNotSent(fn ($r) => str_starts_with($r->url(), 'https://evil.example'));
        Http::assertSentCount(2);
    }

    public function test_token_error_logs_only_status_and_code(): void
    {
        $this->captureLogs();
        $this->fakeToken(['error' => 'invalid_grant', 'error_description' => 'SECRET-DESCRIPTION'], 400);
        try {
            app(GoogleOAuth::class)->exchangeCode('CODE-123', 'VERIFIER-456');
        } catch (OAuthFailed) {
        }
        $all = implode("\n", $this->logged);
        $this->assertStringContainsString('invalid_grant', $all);
        $this->assertStringContainsString('400', $all);
        foreach (['SECRET-DESCRIPTION', 'CODE-123', 'VERIFIER-456', 'test-client-secret', 'ya29'] as $secret) {
            $this->assertStringNotContainsString($secret, $all);
        }
    }

    public function test_unsafe_error_code_is_not_logged(): void
    {
        $this->captureLogs();
        $this->fakeToken(['error' => 'bad code <script> ya29.leak'], 400);
        try {
            app(GoogleOAuth::class)->exchangeCode('c', 'v');
        } catch (OAuthFailed) {
        }
        $all = implode("\n", $this->logged);
        $this->assertStringContainsString('400', $all);
        $this->assertStringNotContainsString('ya29', $all);
        $this->assertStringNotContainsString('script', $all);
    }

    public function test_exception_trace_hides_secret_arguments(): void
    {
        Http::fake([GoogleOAuth::TOKEN_URL => Http::failedConnection('timed out')]);
        try {
            app(GoogleOAuth::class)->exchangeCode('CODE-123', 'VERIFIER-456');
            $this->fail();
        } catch (OAuthFailed $e) {
            $trace = $e->getTraceAsString().print_r($e->getTrace(), true);
            $this->assertStringNotContainsString('CODE-123', $trace);
            $this->assertStringNotContainsString('VERIFIER-456', $trace);
            $this->assertNull($e->getPrevious());
        }
    }

    private static function requestException(int $status): RequestException
    {
        return new RequestException(new Response(new Psr7Response($status, [], '{"error":"x","error_description":"BODY-SECRET ya29.leak"}')));
    }

    public function test_request_exception_during_exchange_is_connection_failure(): void
    {
        $this->captureLogs();
        Http::fake([GoogleOAuth::TOKEN_URL => fn () => throw self::requestException(502)]);
        $this->expectReason('connection', fn () => app(GoogleOAuth::class)->exchangeCode('CODE-123', 'VERIFIER-456'));
        $all = implode("\n", $this->logged);
        $this->assertStringContainsString('"status":502', $all);
        foreach (['BODY-SECRET', 'ya29', 'CODE-123', 'VERIFIER-456', 'error_description'] as $secret) {
            $this->assertStringNotContainsString($secret, $all);
        }
    }

    public function test_request_exception_during_revoke_returns_false(): void
    {
        $this->captureLogs();
        Http::fake([GoogleOAuth::REVOKE_URL => fn () => throw self::requestException(500)]);
        $this->assertFalse(app(GoogleOAuth::class)->revoke('ya29.revoke-me'));
        $all = implode("\n", $this->logged);
        $this->assertStringContainsString('google oauth revoke failed {"status":500}', $all);
        $this->assertStringNotContainsString('BODY-SECRET', $all);
        $this->assertStringNotContainsString('ya29', $all);
    }

    public function test_expires_in_within_margin_is_malformed(): void
    {
        foreach ([1, 60] as $expiresIn) {
            Http::swap(new Factory);
            Http::preventStrayRequests();
            $this->fakeToken(['expires_in' => $expiresIn]);
            $this->expectReason('malformed', fn () => app(GoogleOAuth::class)->exchangeCode('c', 'v'));
        }
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $this->fakeToken(['expires_in' => 61]);
        $this->assertEquals(now()->addSecond()->getTimestamp(), app(GoogleOAuth::class)->exchangeCode('c', 'v')->expiresAt->getTimestamp());
    }

    public static function badAccessTokens(): array
    {
        return [
            'space' => ['ya29 token'], 'newline' => ["ya29\ntoken"], 'non-ascii' => ['ya29.トークン'],
            'control' => ["ya29\x01"], 'too long' => [str_repeat('a', 4097)],
        ];
    }

    #[DataProvider('badAccessTokens')]
    public function test_exchange_rejects_access_token_with_bad_characters(string $accessToken): void
    {
        $this->fakeToken(['access_token' => $accessToken]);
        $this->expectReason('malformed', fn () => app(GoogleOAuth::class)->exchangeCode('c', 'v'));
    }

    public function test_access_token_at_4096_chars_is_accepted(): void
    {
        $this->fakeToken(['access_token' => str_repeat('a', 4096)]);
        $this->assertSame(4096, strlen(app(GoogleOAuth::class)->exchangeCode('c', 'v')->accessToken));
    }

    public function test_revoke_posts_token_in_body(): void
    {
        $this->fakeRevoke();
        $this->assertTrue(app(GoogleOAuth::class)->revoke('ya29.revoke-me'));
        $req = $this->googleRequests(GoogleOAuth::REVOKE_URL)->sole();
        $this->assertSame('POST', $req->method());
        $this->assertSame(GoogleOAuth::REVOKE_URL, $req->url());
        $this->assertStringContainsString('application/x-www-form-urlencoded', $req->header('Content-Type')[0]);
        $this->assertSame(['token' => 'ya29.revoke-me'], $req->data());
    }

    public function test_revoke_failure_returns_false_and_logs_status_only(): void
    {
        $this->captureLogs();
        $this->fakeRevoke(400);
        $this->assertFalse(app(GoogleOAuth::class)->revoke('ya29.revoke-me'));
        $all = implode("\n", $this->logged);
        $this->assertStringContainsString('google oauth revoke failed {"status":400}', $all);
        $this->assertStringNotContainsString('ya29', $all);
    }

    public function test_revoke_connection_failure_returns_false_and_logs_status_only(): void
    {
        $this->captureLogs();
        Http::fake([GoogleOAuth::REVOKE_URL => Http::failedConnection('ya29.revoke-me refused')]);
        $this->assertFalse(app(GoogleOAuth::class)->revoke('ya29.revoke-me'));
        $all = implode("\n", $this->logged);
        $this->assertStringContainsString('google oauth revoke failed {"status":null}', $all);
        $this->assertStringNotContainsString('ya29', $all);
        Http::assertSentCount(1);
    }

    public function test_is_configured_rejects_blank_and_http_in_production(): void
    {
        $this->assertTrue(app(GoogleOAuth::class)->isConfigured());
        config(['services.google.client_secret' => '']);
        $this->assertFalse(app(GoogleOAuth::class)->isConfigured());
        config(['services.google.client_secret' => 's', 'services.google.client_id' => null]);
        $this->assertFalse(app(GoogleOAuth::class)->isConfigured());
        config(['services.google.client_id' => 'cid', 'services.google.redirect' => '  ']);
        $this->assertFalse(app(GoogleOAuth::class)->isConfigured());
        config(['services.google.redirect' => 'http://localhost:8000/auth/google/callback']);
        $this->app['env'] = 'production';
        $this->assertFalse(app(GoogleOAuth::class)->isConfigured());   // http:// の redirect
        config(['services.google.redirect' => 'https://list-maker.alpacasandbag.jp/auth/google/callback']);
        $this->assertTrue(app(GoogleOAuth::class)->isConfigured());
    }

    public function test_endpoints_are_https_constants(): void
    {
        $this->assertSame('https://accounts.google.com/o/oauth2/v2/auth', GoogleOAuth::AUTHORIZE_URL);
        $this->assertSame('https://oauth2.googleapis.com/token', GoogleOAuth::TOKEN_URL);
        $this->assertSame('https://oauth2.googleapis.com/revoke', GoogleOAuth::REVOKE_URL);
        $this->assertSame('https://accounts.google.com', GoogleOAuth::ISSUER);
        $this->assertSame(3600, GoogleOAuth::MAX_EXPIRES_IN);
        $this->assertSame(60, GoogleOAuth::EXPIRY_MARGIN);
        foreach ([GoogleOAuth::AUTHORIZE_URL, GoogleOAuth::TOKEN_URL, GoogleOAuth::REVOKE_URL] as $u) {
            $this->assertStringStartsWith('https://', $u);
        }
        $this->assertStringNotContainsString("config('services.google.", preg_replace('/client_id|client_secret|redirect/', '', file_get_contents(app_path('Services/YouTube/GoogleOAuth.php'))));
    }
}

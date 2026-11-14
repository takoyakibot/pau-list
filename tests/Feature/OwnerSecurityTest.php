<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use App\Services\YouTube\GoogleOAuth;
use App\Services\YouTube\OAuthToken;
use App\Services\YouTube\YouTubeOwnerClient;
use App\Services\YouTube\YouTubeReader;
use App\Support\OwnerLogin;
use App\Support\OwnerSession;
use App\Support\Pkce;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use ReflectionMethod;
use SensitiveParameter;
use Tests\Support\CreatesProposals;
use Tests\Support\FakesGoogle;
use Tests\TestCase;

class OwnerSecurityTest extends TestCase
{
    use CreatesProposals, FakesGoogle, RefreshDatabase;

    private const CLIENT_SECRET = 'CLIENT-SECRET-SENTINEL';

    private const API_KEY = 'API-KEY-SENTINEL';

    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.google' => [
            'client_id' => 'cid.apps.googleusercontent.com',
            'client_secret' => self::CLIENT_SECRET,
            'redirect' => 'http://localhost:8000/auth/google/callback',
        ], 'youtube.api_key' => self::API_KEY]);
        Event::listen(MessageLogged::class, function (MessageLogged $e) {
            $this->logged[] = $e->message.' '.json_encode($e->context);
        });
    }

    private function sessionCookie(TestResponse $res): string
    {
        return (string) $res->getCookie(config('session.cookie'))?->getValue();
    }

    /** Runs start -> callback with the session cookie carried like a browser. Returns [state, cookie, callback response]. */
    private function browserLogin(string $key, string $code): array
    {
        $start = $this->get('/auth/google?p='.$key)->assertRedirect();
        parse_str((string) parse_url($start->headers->get('Location'), PHP_URL_QUERY), $q);
        $cookie = $this->sessionCookie($start);
        $callback = $this->withCookie(config('session.cookie'), $cookie)
            ->get('/auth/google/callback?'.http_build_query(['state' => $q['state'], 'code' => $code]));

        return [$q['state'], $this->sessionCookie($callback) ?: $cookie, $callback];
    }

    private function assertLogsFree(array $needles): void
    {
        $all = implode("\n", $this->logged);
        foreach ($needles as $needle) {
            $this->assertStringNotContainsString($needle, $all);
        }
    }

    public function test_no_token_in_any_table_cache_or_log(): void
    {
        config(['session.driver' => 'database', 'session.encrypt' => true, 'cache.default' => 'database']);
        app('session')->forgetDrivers();
        $key = $this->createProposal();
        $this->fakeToken(['access_token' => 'ya29.MUST-NOT-PERSIST', 'refresh_token' => '1//REFRESH-NOT-PERSIST']);
        $this->fakeMyChannel($this->channelId);
        Http::fake(fn ($request) => str_starts_with($request->url(), YouTubeReader::BASE_URL) ? Http::response(['items' => []]) : null);

        [, $cookie, $callback] = $this->browserLogin($key, '4/0Acode-persist');
        $callback->assertRedirect(route('proposals.edit', $key));
        $this->withCookie(config('session.cookie'), $cookie)->get(route('proposals.edit', $key))->assertOk();

        foreach (DB::connection()->getSchemaBuilder()->getTableListing() as $table) {
            $dump = json_encode(DB::table($table)->get());
            $this->assertStringNotContainsString('MUST-NOT-PERSIST', $dump, $table);
            $this->assertStringNotContainsString('REFRESH-NOT-PERSIST', $dump, $table);
        }
        // The token is in the session (encrypted), and the pre-login session row is gone.
        $this->assertSame(1, DB::table('sessions')->count());
        $this->assertLogsFree(['MUST-NOT-PERSIST', 'REFRESH-NOT-PERSIST', '4/0Acode-persist']);
    }

    public function test_secrets_never_reach_logs(): void
    {
        $secrets = [self::CLIENT_SECRET, self::API_KEY, 'ya29.TOKEN-SENTINEL', 'CODE-SENTINEL', 'Bearer'];
        $mode = 'token500';
        Http::fake(function (Request $request) use (&$mode) {
            $url = $request->url();
            if (str_starts_with($url, GoogleOAuth::TOKEN_URL)) {
                return match ($mode) {
                    // The body echoes secrets back.
                    'token500' => Http::response(['error' => 'server_error', 'error_description' => 'echo '.self::CLIENT_SECRET.' CODE-SENTINEL'], 500),
                    'connection' => throw new ConnectionException('cURL error for code=CODE-SENTINEL secret='.self::CLIENT_SECRET),
                    default => Http::response(['access_token' => 'ya29.TOKEN-SENTINEL', 'expires_in' => 3599, 'token_type' => 'Bearer', 'scope' => GoogleOAuth::SCOPE]),
                };
            }
            if (str_starts_with($url, YouTubeReader::BASE_URL)) {
                return Http::response(['error' => ['code' => 401, 'message' => 'bad ya29.TOKEN-SENTINEL key '.self::API_KEY, 'errors' => [['reason' => 'authError']]]], 401);
            }
            if (str_starts_with($url, GoogleOAuth::REVOKE_URL)) {
                return Http::response(['error' => 'invalid_token', 'error_description' => 'ya29.TOKEN-SENTINEL'], 400);
            }

            return null;
        });
        $key = $this->createProposal();

        // 1. Token endpoint 500. 2. Token endpoint connection error.
        // 3. channels.list 401 after a successful exchange, then 4. the revoke of that token answers 400.
        foreach (['token500', 'connection', 'ok'] as $m) {
            $mode = $m;
            $start = $this->get('/auth/google?p='.$key)->assertRedirect();
            parse_str((string) parse_url($start->headers->get('Location'), PHP_URL_QUERY), $q);
            $secrets[] = $q['state'];
            $secrets[] = session(OwnerSession::PENDING_KEY)['verifier'];
            $this->get('/auth/google/callback?'.http_build_query(['state' => $q['state'], 'code' => 'CODE-SENTINEL']))
                ->assertRedirect(route('proposals.show', $key));
        }
        $this->assertCount(1, $this->googleRequests(GoogleOAuth::REVOKE_URL));
        $this->assertFalse(session()->has(OwnerSession::OWNER_KEY));

        // 5. Logout whose revoke answers 400.
        $this->actAsOwner($this->channelId, token: 'ya29.TOKEN-SENTINEL')->post(route('owner.logout'))->assertRedirect(route('owner.logged-out'));
        $this->assertCount(2, $this->googleRequests(GoogleOAuth::REVOKE_URL));

        $this->assertGreaterThanOrEqual(5, count($this->logged), 'every failure path logs');
        $this->assertLogsFree($secrets);
    }

    public function test_secret_parameters_are_sensitive(): void
    {
        $cases = [
            [GoogleOAuth::class, 'exchangeCode', ['code', 'verifier']],
            [GoogleOAuth::class, 'revoke', ['token']],
            [GoogleOAuth::class, 'authorizationUrl', ['state']],
            [YouTubeOwnerClient::class, 'myChannel', ['accessToken']],
            [YouTubeOwnerClient::class, 'unlistedUploads', ['accessToken']],
            [OwnerLogin::class, '__construct', ['accessToken']],
            [OAuthToken::class, '__construct', ['accessToken']],
            [Pkce::class, 'challenge', ['verifier']],
        ];
        foreach ($cases as [$class, $method, $params]) {
            $found = [];
            foreach ((new ReflectionMethod($class, $method))->getParameters() as $p) {
                if (in_array($p->getName(), $params, true)) {
                    $found[] = $p->getName();
                    $this->assertNotEmpty($p->getAttributes(SensitiveParameter::class), "$class::$method \$".$p->getName());
                }
            }
            $this->assertSame($params, $found, "$class::$method parameters exist");
        }
    }

    public function test_tls_verification_is_never_disabled(): void
    {
        foreach (File::allFiles(app_path()) as $f) {
            $src = $f->getContents();
            $this->assertDoesNotMatchRegularExpression('/withoutVerifying|[\'"]verify[\'"]\s*=>\s*false/', $src, $f->getRelativePathname());
        }
    }

    public function test_client_secret_never_reaches_html_or_frontend(): void
    {
        $key = $this->createProposal();
        $other = 'UC'.str_repeat('z', 22);
        Http::fake(fn ($request) => str_starts_with($request->url(), YouTubeReader::BASE_URL) ? Http::response(['items' => []]) : null);
        $this->fakeRevoke();

        $pages = [
            $this->get(route('proposals.show', $key)),                                    // S4 logged out
            $this->actAsOwner($this->channelId)->get(route('proposals.show', $key)),     // S4 logged in
            $this->actAsOwner($this->channelId)->get(route('proposals.edit', $key)),     // S5
            $this->actAsOwner($other)->get(route('proposals.edit', $key)),               // mismatch
            $this->get('/auth/google/callback?state=x&code=y'),                           // callback-invalid
        ];
        $this->actAsOwner($this->channelId)->post(route('owner.logout'));
        $pages[] = $this->get(route('owner.logged-out'));
        foreach ($pages as $res) {
            $this->assertStringNotContainsString(self::CLIENT_SECRET, $res->getContent());
        }

        $sources = [base_path('.env.example'), base_path('vite.config.js')];
        foreach (File::allFiles(resource_path('js')) as $f) {
            $sources[] = $f->getPathname();
        }
        foreach ($sources as $path) {
            $src = file_get_contents($path);
            $this->assertDoesNotMatchRegularExpression('/VITE_[A-Z_]*GOOGLE/', $src, $path);
            $this->assertStringNotContainsString('GOOGLE_CLIENT_SECRET', $path === base_path('.env.example') ? '' : $src, $path);
        }
    }

    public function test_debug_in_production_logs_warning(): void
    {
        $this->app['env'] = 'production';
        config(['app.debug' => true]);
        $this->logged = [];
        (new AppServiceProvider($this->app))->warnAboutDebugInProduction();
        $this->assertContains('APP_DEBUG is enabled in production []', $this->logged);

        config(['app.debug' => false]);
        $this->logged = [];
        (new AppServiceProvider($this->app))->warnAboutDebugInProduction();
        $this->assertSame([], $this->logged);

        $this->app['env'] = 'testing';
        config(['app.debug' => true]);
        (new AppServiceProvider($this->app))->warnAboutDebugInProduction();
        $this->assertSame([], $this->logged);
    }

    public function test_boot_runs_the_debug_check(): void
    {
        $this->app['env'] = 'production';
        config(['app.debug' => true]);
        $this->logged = [];
        try {
            (new AppServiceProvider($this->app))->boot();
        } finally {
            $this->app['env'] = 'testing';
        }
        $this->assertContains('APP_DEBUG is enabled in production []', $this->logged);
    }

    public function test_no_stateless_mode_exists(): void
    {
        foreach (File::allFiles(app_path()) as $f) {
            $this->assertStringNotContainsStringIgnoringCase('stateless', $f->getContents(), $f->getRelativePathname());
        }
        foreach (['services.google', 'listmaker', 'youtube'] as $cfg) {
            $this->assertStringNotContainsStringIgnoringCase('stateless', json_encode(config($cfg)), $cfg);
        }
    }
}

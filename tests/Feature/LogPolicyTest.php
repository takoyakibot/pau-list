<?php

namespace Tests\Feature;

use App\Models\PlaylistCreation;
use App\Services\Creation\CreationRunner;
use App\Services\YouTube\QuotaLedger;
use App\Support\ChannelFetchThrottle;
use App\Support\OwnerLogin;
use App\Support\ProposalKey;
use App\Support\ProposalStoreLimits;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\Support\CreatesProposals;
use Tests\Support\FakesGoogle;
use Tests\Support\FakesYouTube;
use Tests\TestCase;

/** Spec 8.8: what is logged, and that secrets, IPs, bindings and URLs never are. */
class LogPolicyTest extends TestCase
{
    use CreatesProposals, FakesGoogle, FakesYouTube, RefreshDatabase;

    /** @var list<MessageLogged> */
    private array $logs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-15 03:00:00'));
        Event::listen(MessageLogged::class, function (MessageLogged $e) {
            $this->logs[] = $e;
        });
    }

    /** @return list<MessageLogged> */
    private function logsWithMessage(string $message): array
    {
        return array_values(array_filter($this->logs, fn (MessageLogged $e) => $e->message === $message));
    }

    /** Message, context and (for exceptions) the trace as a log formatter would write it. */
    private function allLogText(): string
    {
        $out = '';
        foreach ($this->logs as $e) {
            $out .= $e->message.json_encode($e->context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
            foreach ($e->context as $value) {
                // Like the log formatter, follow the previous-exception chain.
                for ($t = $value instanceof \Throwable ? $value : null; $t !== null; $t = $t->getPrevious()) {
                    $out .= $t->getMessage().$t->getTraceAsString();
                }
            }
        }

        return $out;
    }

    private function assertLogsDoNotContain(string $needle): void
    {
        $this->assertStringNotContainsString($needle, $this->allLogText());
    }

    public function test_unhandled_query_exception_logs_sqlstate_only(): void
    {
        Route::get('/_boom', fn () => throw new QueryException('sqlite', 'select * from proposals where key_hash = ?', ['SECRETBINDING'], new \PDOException('x')));
        config(['app.debug' => false]);

        $this->get('/_boom')->assertStatus(500);

        $this->assertCount(1, $this->logsWithMessage('Database error'));
        $this->assertSame(['sqlstate' => '0'], $this->logsWithMessage('Database error')[0]->context);
        $this->assertLogsDoNotContain('SECRETBINDING');
        $this->assertLogsDoNotContain('key_hash = ?');
    }

    public function test_unhandled_http_client_exception_logs_without_url(): void
    {
        Http::fake(['*' => Http::response('', 500)]);
        Route::get('/_boom', fn () => Http::get('https://example.test/x?key=SECRETKEY')->throw());
        config(['app.debug' => false]);

        $this->get('/_boom')->assertStatus(500);

        $this->assertCount(1, $this->logsWithMessage('HTTP client error'));
        $this->assertSame(['exception' => RequestException::class, 'status' => 500], $this->logsWithMessage('HTTP client error')[0]->context);
        $this->assertLogsDoNotContain('SECRETKEY');
        $this->assertLogsDoNotContain('example.test');
    }

    public function test_wrapped_query_exception_logs_sqlstate_only(): void
    {
        $query = new QueryException('sqlite', 'select * from proposals where key_hash = ?', ['SECRETBINDING'], new \PDOException('x'));
        Route::get('/_boom', fn () => throw new \RuntimeException('wrapped: '.$query->getMessage(), 0, $query));
        config(['app.debug' => false]);

        $this->get('/_boom')->assertStatus(500);

        $this->assertCount(1, $this->logsWithMessage('Database error'));
        $this->assertSame(['sqlstate' => '0', 'wrapped_in' => \RuntimeException::class], $this->logsWithMessage('Database error')[0]->context);
        $this->assertLogsDoNotContain('SECRETBINDING');
        $this->assertLogsDoNotContain('key_hash = ?');
    }

    public function test_wrapped_http_client_exception_logs_without_url(): void
    {
        Http::fake(['*' => Http::response('', 503)]);
        Route::get('/_boom', function () {
            try {
                Http::get('https://example.test/x?key=SECRETKEY')->throw();
            } catch (RequestException $e) {
                throw new \LogicException('outer: '.$e->getMessage(), 0, new \RuntimeException('middle', 0, $e));
            }
        });
        config(['app.debug' => false]);

        $this->get('/_boom')->assertStatus(500);

        $this->assertCount(1, $this->logsWithMessage('HTTP client error'));
        $this->assertSame(
            ['exception' => RequestException::class, 'status' => 503, 'wrapped_in' => \LogicException::class],
            $this->logsWithMessage('HTTP client error')[0]->context,
        );
        $this->assertLogsDoNotContain('SECRETKEY');
        $this->assertLogsDoNotContain('example.test');
    }

    public function test_unhandled_exception_trace_has_no_argument_values(): void
    {
        // A trace with argument values would carry e.g. the proposal key passed to a controller method.
        $key = ProposalKey::generate();
        $thrower = new class
        {
            public function fail(string $key): never
            {
                throw new \RuntimeException('boom');
            }
        };
        Route::get('/_boom/{key}', fn (string $key) => $thrower->fail($key));
        config(['app.debug' => false]);

        $this->get('/_boom/'.$key)->assertStatus(500);

        $this->assertNotEmpty($this->logs);
        $this->assertLogsDoNotContain(substr($key, 0, 10));
        $this->assertLogsDoNotContain('/_boom/');
    }

    public function test_throttled_request_logs_limiter_without_ip(): void
    {
        for ($i = 0; $i < 120; $i++) {
            $this->get('/')->assertOk();
        }
        $this->get('/')->assertStatus(429);

        $logged = $this->logsWithMessage('Rate limit exceeded');
        $this->assertCount(1, $logged);
        $this->assertSame(['limiter' => 'route:home'], $logged[0]->context);
        $this->assertLogsDoNotContain('127.0.0.1');
    }

    public function test_channel_fetch_refusal_is_logged(): void
    {
        $req = Request::create('/', 'GET', server: ['REMOTE_ADDR' => '127.0.0.1']);
        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue(app(ChannelFetchThrottle::class)->attempt($req));
        }

        $this->get('/channels?q=%40nobody')->assertStatus(429);

        $logged = $this->logsWithMessage('Rate limit exceeded');
        $this->assertCount(1, $logged);
        $this->assertSame(['limiter' => 'channel-fetch'], $logged[0]->context);
    }

    public function test_proposal_store_success_limit_is_logged_once(): void
    {
        $req = Request::create('/', 'GET', server: ['REMOTE_ADDR' => '127.0.0.1']);
        for ($i = 0; $i < 5; $i++) {
            app(ProposalStoreLimits::class)->hit($req);
        }

        $this->post(route('proposals.store', $this->channelId), ['title' => 't', 'video_ids' => ['aaaaaaaaaaa']])->assertRedirect();

        $logged = $this->logsWithMessage('Rate limit exceeded');
        $this->assertCount(1, $logged);
        $this->assertSame(['limiter' => 'proposal-store'], $logged[0]->context);
    }

    public function test_read_budget_exhaustion_is_logged_once_per_day(): void
    {
        Http::fake();
        $ledger = app(QuotaLedger::class);
        $ledger->record(QuotaLedger::READ, (int) config('youtube.quota.read_budget'));

        $this->get('/channels?q=%40nobody')->assertRedirect();
        $this->get('/channels?q=%40nobody2')->assertRedirect();
        $this->assertCount(1, $this->logsWithMessage('Read budget exhausted'));
        $this->assertSame([], $this->logsWithMessage('Read budget exhausted')[0]->context);

        $this->travelTo(CarbonImmutable::parse('2026-11-16 09:00:00')); // the next PT day
        $ledger->record(QuotaLedger::READ, (int) config('youtube.quota.read_budget'));
        $this->get('/channels?q=%40nobody3')->assertRedirect();
        $this->assertCount(2, $this->logsWithMessage('Read budget exhausted'));
    }

    public function test_quota_pause_is_logged(): void
    {
        $c = $this->makeCreation(2, ['status' => PlaylistCreation::ADDING, 'youtube_playlist_id' => 'PLx'], 100);
        $this->fakePlaylistItemInsert([[403, 'quotaExceeded']]);

        app(CreationRunner::class)->step($c, new OwnerLogin('ya29.tok', now()->toImmutable()->addHour(), $this->channelId, 'ch'));

        $logged = $this->logsWithMessage('Creation paused');
        $this->assertCount(1, $logged);
        $this->assertSame(['creation_id' => $c->id, 'reason' => PlaylistCreation::PAUSE_QUOTA_EXCEEDED], $logged[0]->context);
        $this->assertLogsDoNotContain('ya29.tok');
    }

    public function test_insufficient_write_quota_is_logged(): void
    {
        $this->makeCreation(3, reserve: 4880); // free 120
        $key = $this->createProposal();
        $this->fakeVideos([
            $this->videoResource('aaaaaaaaaaa', $this->channelId),
            $this->videoResource('bbbbbbbbbbb', $this->channelId),
        ]);

        $this->actAsOwner($this->channelId)
            ->postJson("/p/$key/creation", ['title' => 't', 'description' => '', 'privacy' => 'private', 'video_ids' => ['aaaaaaaaaaa', 'bbbbbbbbbbb']], ['Sec-Fetch-Site' => 'same-origin'])
            ->assertStatus(409);

        $logged = $this->logsWithMessage('Write quota insufficient');
        $this->assertCount(1, $logged);
        $this->assertSame(['items' => 2, 'free_units' => 120], $logged[0]->context);
        $this->assertLogsDoNotContain($key);
    }

    public function test_no_log_call_passes_secrets(): void
    {
        $forbidden = ['->ip()', 'access_token', 'accessToken', 'reply_address', 'replyAddress', '$body', "'key_hash' =>", '->getQuery', 'url(', 'fullUrl', '->path()'];
        $calls = 0;
        foreach (File::allFiles(app_path()) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            preg_match_all('/Log::\w+\((.*?)\);/s', $file->getContents(), $m);
            foreach ($m[1] as $args) {
                $calls++;
                foreach ($forbidden as $f) {
                    $this->assertStringNotContainsString($f, $args, $file->getRelativePathname().': '.$args);
                }
            }
        }
        $this->assertGreaterThan(20, $calls);
    }
}

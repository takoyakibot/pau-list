<?php

namespace Tests\Feature;

use App\Models\ChannelCache;
use App\Models\Proposal;
use App\Services\YouTube\QuotaLedger;
use App\Support\ProposalKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\FailsQueries;
use Tests\Support\FakesYouTube;
use Tests\TestCase;

class StoreProposalTest extends TestCase
{
    use FailsQueries, FakesYouTube, RefreshDatabase;

    private const A = 'aaaaaaaaaaa';

    private const B = 'bbbbbbbbbbb';

    private string $id;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-14 03:00:00'));
        $this->id = 'UC'.str_repeat('a', 22);
        $now = CarbonImmutable::now();
        ChannelCache::create([
            'channel_id' => $this->id,
            'handle' => '@a',
            'title' => 'チャンネル A',
            'thumbnail_url' => 'https://yt3.ggpht.com/a',
            'uploads_playlist_id' => 'UU'.substr($this->id, 2),
            'meta_fetched_at' => $now,
            'cached_since' => $now,
            'last_synced_at' => $now,
            'truncated' => false,
            'expires_at' => $now->addDays(30),
        ]);
    }

    private function store(array $data, ?string $channelId = null)
    {
        return $this->post(route('proposals.store', $channelId ?? $this->id), $data);
    }

    private function keyFrom($res): string
    {
        $this->assertSame(1, preg_match('#/p/('.ProposalKey::ROUTE_PATTERN.')/shared$#', (string) $res->headers->get('Location'), $m));

        return $m[1];
    }

    private function assertNoApiCalls(): void
    {
        $this->assertCount(0, app(Factory::class)->recorded());
    }

    public function test_saves_server_values_in_submitted_order_and_hashes_key(): void
    {
        $id = $this->id;
        $this->fakeVideos([
            $this->videoResource(self::B, $id, ['title' => 'サーバー値', 'duration' => 'PT1M5S']),
            $this->videoResource(self::A, $id),
        ]);
        $res = $this->store(['title' => ' 案 ', 'video_ids' => [self::B, self::A], 'video_titles' => ['偽装']]);
        $p = Proposal::sole();
        $key = $this->keyFrom($res);
        // the session only ever holds the hash, never the raw key
        $this->assertSame(ProposalKey::hash($key), session('proposal_key_shown'));
        $res->assertRedirect(route('proposals.shared', $key));
        $this->assertSame(ProposalKey::hash($key), $p->key_hash);
        $this->assertSame('案', $p->title);
        $this->assertSame('', $p->description);
        $this->assertNull($p->comment);
        $this->assertNull($p->proposer_name);
        $this->assertSame(2, $p->item_count);
        $this->assertSame('チャンネル A', $p->channel_title);
        $this->assertSame('https://yt3.ggpht.com/a', $p->channel_thumbnail_url);
        $this->assertSame([self::B, self::A], $p->items->pluck('video_id')->all());
        $this->assertSame([0, 1], $p->items->pluck('position')->all());
        $this->assertSame('サーバー値', $p->items[0]->title);
        $this->assertSame(65, $p->items[0]->duration_seconds);
        $this->assertSame('https://i.ytimg.com/vi/'.self::B.'/mqdefault.jpg', $p->items[0]->thumbnail_url);
        $this->assertFalse($p->items[0]->is_outside_channel);
        $this->assertTrue($p->items[0]->published_at->equalTo(CarbonImmutable::parse('2025-01-02T03:04:05Z')));
        $this->assertTrue($p->expires_at->equalTo(now()->addDays(30)));
        $this->assertSame(1, app(QuotaLedger::class)->used(QuotaLedger::READ));
        foreach (['proposals', 'proposal_items'] as $t) {
            $this->assertStringNotContainsString($key, json_encode(DB::table($t)->get()));
        }
        $this->assertSame($p->id, Proposal::findLiveByKey($key)?->id);
    }

    public function test_find_live_by_key_rejects_malformed_unknown_and_expired(): void
    {
        $this->fakeVideos([$this->videoResource(self::A, $this->id)]);
        $key = $this->keyFrom($this->store(['title' => 't', 'video_ids' => [self::A]]));
        $this->assertNull(Proposal::findLiveByKey('short'));
        $this->assertNull(Proposal::findLiveByKey(ProposalKey::generate()));
        $this->travel(30)->days();
        $this->assertNull(Proposal::findLiveByKey($key));
    }

    public function test_rejects_more_than_max_items(): void
    {
        Http::fake();
        $ids = [];
        for ($i = 0; $i < 100; $i++) {
            $ids[] = 'v'.str_pad((string) $i, 10, '0', STR_PAD_LEFT);
        }
        $this->store(['title' => 't', 'video_ids' => $ids])->assertSessionHasErrors(['video_ids' => '1 つの再生リストに入れられるのは最大 99 本です']);
        $this->assertSame(0, Proposal::count());
        $this->assertNoApiCalls();
    }

    public function test_rejects_duplicates_and_bad_ids(): void
    {
        Http::fake();
        $this->store(['title' => 't', 'video_ids' => [self::A, self::A]])->assertSessionHasErrors(['video_ids.0' => '同じ動画が重複しています']);
        $this->store(['title' => 't', 'video_ids' => ['bad id!']])->assertSessionHasErrors(['video_ids.0' => '動画 ID の形式が正しくありません']);
        $this->store(['title' => 't', 'video_ids' => ["aaaaa\naaaaa"]])->assertSessionHasErrors('video_ids.0');
        $this->store(['title' => 't', 'video_ids' => []])->assertSessionHasErrors(['video_ids' => '動画を 1 本以上選んでください']);
        $this->store(['title' => 't'])->assertSessionHasErrors(['video_ids' => '動画を 1 本以上選んでください']);
        $this->travel(11)->minutes(); // stay under the 5 per 10 minutes limit
        $this->store(['title' => '', 'video_ids' => [self::A]])->assertSessionHasErrors(['title' => '再生リスト名を入力してください']);
        $this->store(['title' => 't', 'video_ids' => [self::A], 'comment' => str_repeat('a', 501)])
            ->assertSessionHasErrors(['comment' => 'チャンネル主へのコメントは 500 文字以内で入力してください']);
        $this->store(['title' => 't', 'video_ids' => [self::A], 'proposer_name' => str_repeat('a', 31)])
            ->assertSessionHasErrors(['proposer_name' => 'ニックネームは 30 文字以内で入力してください']);
        $this->store(['title' => 't', 'video_ids' => [self::A], 'description' => str_repeat('a', 1001)])
            ->assertSessionHasErrors(['description' => '再生リストの説明は 1000 文字以内で入力してください']);
        $this->assertSame(0, Proposal::count());
        $this->assertNoApiCalls();
    }

    public function test_deleted_or_private_video_rejects_save_and_flags_ids(): void
    {
        // only A is returned (B deleted)
        $this->fakeVideos([$this->videoResource(self::A, $this->id)]);
        $this->from(route('channels.propose', $this->id))
            ->store(['title' => 't', 'video_ids' => [self::A, self::B]])
            ->assertRedirect(route('channels.propose', $this->id))
            ->assertSessionHasErrors(['video_ids' => 'この動画は削除または非公開になっています。外して保存してください'])
            ->assertSessionHas('unavailable_video_ids', [self::B])
            ->assertSessionHasInput('title', 't');
        $this->assertSame(0, Proposal::count());
    }

    public function test_private_video_rejects_save(): void
    {
        $this->fakeVideos([$this->videoResource(self::A, $this->id), $this->videoResource(self::B, $this->id, ['privacy' => 'private'])]);
        $this->store(['title' => 't', 'video_ids' => [self::A, self::B]])
            ->assertSessionHasErrors(['video_ids' => 'この動画は削除または非公開になっています。外して保存してください'])
            ->assertSessionHas('unavailable_video_ids', [self::B]);
        $this->assertSame(0, Proposal::count());
    }

    public function test_outside_channel_video_is_included_with_flag(): void
    {
        $other = 'UC'.str_repeat('b', 22);
        $this->fakeVideos([
            $this->videoResource(self::A, $this->id),
            $this->videoResource(self::B, $other, ['channelTitle' => '別チャンネル']),
        ]);
        $this->store(['title' => 't', 'video_ids' => [self::A, self::B]])->assertSessionHasNoErrors();
        $items = Proposal::sole()->items;
        $this->assertFalse($items[0]->is_outside_channel);
        $this->assertTrue($items[1]->is_outside_channel);
        $this->assertSame($other, $items[1]->video_channel_id);
        $this->assertSame('別チャンネル', $items[1]->video_channel_title);
    }

    public function test_honeypot_saves_nothing(): void
    {
        Http::fake();
        $this->store(['title' => 't', 'video_ids' => [self::A], 'website' => 'x'])
            ->assertRedirect(route('home'))
            ->assertSessionHasNoErrors();
        $this->assertSame(0, Proposal::count());
        $this->assertNull(session('proposal_key_shown'));
        // invalid input with the honeypot filled gets the same silent redirect
        $this->store(['title' => '', 'video_ids' => ['bad'], 'website' => 'x'])
            ->assertRedirect(route('home'))
            ->assertSessionHasNoErrors();
        $this->assertSame(0, Proposal::count());
        $this->assertNoApiCalls();
    }

    private function assertThrottledBackToForm($res): void
    {
        $res->assertRedirect(route('channels.propose', $this->id))
            ->assertSessionHasErrors(['save' => '保存の操作が集中しています。しばらく待ってから再度お試しください（入力内容は残っています）'])
            ->assertSessionHasInput('title', '残す')
            ->assertSessionHasInput('comment', 'c')
            ->assertSessionHasInput('video_ids', [self::A, self::B]);
        $this->assertFalse(session()->hasOldInput('_token'));
        $this->assertFalse(session()->hasOldInput('website'));
    }

    public function test_only_successful_saves_count_toward_5_in_10_minutes(): void
    {
        $this->fakeVideos([$this->videoResource(self::A, $this->id), $this->videoResource(self::B, $this->id)]);
        // user decision #19(1): failures never use up the success limit
        for ($i = 0; $i < 3; $i++) {
            $this->store(['title' => '', 'video_ids' => [self::A]])->assertSessionHasErrors('title');
            $this->store(['title' => 't', 'video_ids' => [self::A, 'ccccccccccc']])->assertSessionHasErrors('video_ids');
            $this->store(['title' => 't', 'video_ids' => [self::A], 'website' => 'x'])->assertRedirect(route('home'));
        }
        for ($i = 0; $i < 5; $i++) {
            $this->store(['title' => 't', 'video_ids' => [self::A]])->assertSessionHasNoErrors();
        }
        $this->assertSame(5, Proposal::count());

        // the 6th success-to-be is refused before saving: back to the form with the input kept
        $this->assertThrottledBackToForm(
            $this->store(['title' => '残す', 'comment' => 'c', 'video_ids' => [self::A, self::B], 'website' => '']),
        );
        $this->assertSame(5, Proposal::count());
        // a cross-site POST gets the plain 429 page (no input injected into the visitor's form)
        $this->post(route('proposals.store', $this->id), ['title' => 'x', 'video_ids' => [self::A]], ['Sec-Fetch-Site' => 'cross-site'])
            ->assertStatus(429)->assertHeader('Retry-After');
        // JSON clients keep the JSON 429
        $this->postJson(route('proposals.store', $this->id), ['title' => 'x', 'video_ids' => [self::A]])
            ->assertStatus(429)->assertJsonStructure(['message']);
        $this->assertSame(5, Proposal::count());

        $this->travel(11)->minutes();
        $this->store(['title' => 't', 'video_ids' => [self::A]])->assertSessionHasNoErrors();
        $this->assertSame(6, Proposal::count());
    }

    public function test_only_successful_saves_count_toward_30_per_day(): void
    {
        $this->fakeVideos([$this->videoResource(self::A, $this->id)]);
        for ($n = 0; $n < 30; $n++) {
            if ($n > 0 && $n % 5 === 0) {
                $this->travel(11)->minutes();
            }
            $this->store(['title' => '', 'video_ids' => [self::A]])->assertSessionHasErrors('title');
            $this->store(['title' => 't', 'video_ids' => [self::A]])->assertSessionHasNoErrors();
        }
        $this->travel(11)->minutes();
        $this->store(['title' => 't', 'video_ids' => [self::A]])->assertSessionHasErrors('save');
        $this->assertSame(30, Proposal::count());
        $this->travel(1)->days();
        // a day later the channel meta is stale and refetched at save
        $this->fakeChannel($this->id, ['title' => 'チャンネル A', 'handle' => '@a']);
        $this->store(['title' => 't', 'video_ids' => [self::A]])->assertSessionHasNoErrors();
        $this->assertSame(31, Proposal::count());
    }

    public function test_500_successful_saves_per_day_across_all_ips(): void
    {
        $this->fakeVideos([$this->videoResource(self::A, $this->id)]);
        RateLimiter::increment('proposal-store:all', 86400, 499);
        $this->store(['title' => 't', 'video_ids' => [self::A]])->assertSessionHasNoErrors();
        $this->call('POST', route('proposals.store', $this->id), ['title' => 't', 'video_ids' => [self::A]], [], [], ['REMOTE_ADDR' => '198.51.100.7'])
            ->assertSessionHasErrors('save');
        $this->assertSame(1, Proposal::count());
    }

    public function test_every_post_counts_toward_20_in_10_minutes(): void
    {
        Http::fake();
        // validation failures and honeypot hits all use up this limit
        for ($i = 0; $i < 10; $i++) {
            $this->store(['title' => '', 'video_ids' => [self::A]])->assertSessionHasErrors('title');
            $this->store(['title' => 't', 'video_ids' => [self::A], 'website' => 'x'])->assertRedirect(route('home'));
        }
        $this->assertThrottledBackToForm(
            $this->store(['title' => '残す', 'comment' => 'c', 'video_ids' => [self::A, self::B], 'website' => '']),
        );
        $this->postJson(route('proposals.store', $this->id), ['title' => 'x'])->assertStatus(429)->assertHeader('Retry-After');
        $this->assertNoApiCalls();
        // per IP
        $this->call('POST', route('proposals.store', $this->id), ['title' => ''], [], [], ['REMOTE_ADDR' => '198.51.100.7'])
            ->assertSessionHasErrors('title');
        $this->travel(11)->minutes();
        $this->store(['title' => '', 'video_ids' => [self::A]])->assertSessionHasErrors('title');
    }

    public function test_description_crlf_counts_as_one_char_and_emoji_title_at_limit_passes(): void
    {
        $this->fakeVideos([$this->videoResource(self::A, $this->id)]);
        $this->store(['title' => str_repeat('😀', 150), 'description' => str_repeat("あ\r\n", 500), 'video_ids' => [self::A]])
            ->assertSessionHasNoErrors();
        $p = Proposal::sole();
        $this->assertStringNotContainsString("\r", $p->description);
        // trailing newline trimmed: 500 "あ" + 499 "\n"
        $this->assertSame(999, mb_strlen($p->description));
        $this->assertSame(150, mb_strlen($p->title));

        $this->store(['title' => str_repeat('😀', 151), 'video_ids' => [self::A]])
            ->assertSessionHasErrors(['title' => '再生リスト名は 150 文字以内で入力してください']);
        $this->store(['title' => 't', 'description' => str_repeat("あ\r\n", 500).'x', 'video_ids' => [self::A]])
            ->assertSessionHasErrors('description');
    }

    public function test_control_characters_are_stripped(): void
    {
        $this->fakeVideos([$this->videoResource(self::A, $this->id)]);
        $this->store([
            'title' => "a\u{0007}b\nc",
            'description' => "x\r\ny\tz\u{0000}\u{001B}",
            'comment' => "  \n ",
            'proposer_name' => "な\tまえ\u{007F}",
            'video_ids' => [self::A],
        ])->assertSessionHasNoErrors();
        $p = Proposal::sole();
        $this->assertSame('abc', $p->title);
        $this->assertSame("x\ny\tz", $p->description);
        $this->assertNull($p->comment);
        $this->assertSame('なまえ', $p->proposer_name);
    }

    public function test_missing_channel_cache_redirects_to_propose(): void
    {
        Http::fake();
        ChannelCache::query()->update(['expires_at' => now()]);
        $this->store(['title' => 't', 'video_ids' => [self::A]])
            ->assertRedirect(route('channels.propose', $this->id))
            ->assertSessionHasErrors(['video_ids' => '一覧の保存期限が切れたため開き直しました。もう一度保存してください']);
        $other = 'UC'.str_repeat('z', 22);
        $this->store(['title' => 't', 'video_ids' => [self::A]], $other)
            ->assertRedirect(route('channels.propose', $other));
        $this->assertSame(0, Proposal::count());
        $this->assertNoApiCalls();
    }

    public function test_budget_exhausted_and_api_error_messages(): void
    {
        config(['youtube.quota.read_budget' => 0]);
        Http::fake();
        $this->store(['title' => 't', 'video_ids' => [self::A]])
            ->assertSessionHasErrors(['video_ids' => '本日の取得上限に達したため、いまは保存できません。時間をおいて再度お試しください']);
        $this->assertNoApiCalls();

        config(['youtube.quota.read_budget' => 2000]);
        $this->fakeYouTubeError('videos', 500, 'backendError');
        $this->store(['title' => 't', 'video_ids' => [self::A]])
            ->assertSessionHasErrors(['video_ids' => 'YouTube との通信に失敗しました。時間をおいて再度お試しください']);
        $this->assertSame(0, Proposal::count());
    }

    public function test_log_has_only_hash_prefix(): void
    {
        $this->fakeVideos([$this->videoResource(self::A, $this->id)]);
        Log::spy();
        $key = $this->keyFrom($this->store(['title' => 't', 'video_ids' => [self::A]]));
        $hash = ProposalKey::hash($key);
        Log::shouldHaveReceived('info')->withArgs(function ($msg, $ctx = []) use ($key, $hash) {
            $all = $msg.json_encode($ctx);

            return ! str_contains($all, $key) && ! str_contains($all, $hash) && str_contains($all, substr($hash, 0, 8));
        })->once();
    }

    public function test_failed_save_after_url_lookup_returns_to_propose_screen(): void
    {
        $other = 'UC'.str_repeat('b', 22);
        $this->fakeVideos([$this->videoResource(self::A, $this->id), $this->videoResource(self::B, $other)]);
        $this->get(route('channels.propose', $this->id))->assertOk();
        // a lookup fetch must not become the "previous URL" (no-referrer makes back() use the session)
        $this->get(route('channels.video', $this->id).'?url='.urlencode('https://youtu.be/'.self::B), ['Sec-Fetch-Site' => 'same-origin'])->assertOk();
        $this->get(route('channels.video', $this->id).'?url='.urlencode('https://youtu.be/'.self::A), ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'])->assertOk();

        // service error path
        $this->store(['title' => 't', 'video_ids' => [self::A, 'ccccccccccc']])
            ->assertRedirect(route('channels.propose', $this->id))
            ->assertSessionHasErrors('video_ids')
            ->assertSessionHasInput('video_ids', [self::A, 'ccccccccccc']);
        // validation error path
        $this->store(['title' => '', 'video_ids' => [self::A, self::B]])
            ->assertRedirect(route('channels.propose', $this->id))
            ->assertSessionHasErrors('title')
            ->assertSessionHasInput('video_ids', [self::A, self::B]);
        // budget path
        config(['youtube.quota.read_budget' => 0]);
        $this->store(['title' => 't', 'video_ids' => [self::A]])
            ->assertRedirect(route('channels.propose', $this->id))
            ->assertSessionHasErrors('video_ids');
    }

    public function test_invisible_and_bidi_characters_are_stripped(): void
    {
        $this->fakeVideos([$this->videoResource(self::A, $this->id)]);
        $this->store(['title' => "\u{200B}\u{FEFF}", 'video_ids' => [self::A]])
            ->assertSessionHasErrors(['title' => '再生リスト名を入力してください']);
        // ZWJ is kept for joined emoji, but a title of only ZWJ (and spaces) counts as empty
        $this->store(['title' => " \u{200D}\u{200B}\u{200D} ", 'video_ids' => [self::A]])
            ->assertSessionHasErrors(['title' => '再生リスト名を入力してください']);
        $this->assertNoApiCalls();

        $this->store([
            'title' => "a\u{202E}b\u{2066}c\u{2069}d\u{200C}",
            'description' => "x\u{202A}\ny\u{200C}",
            'comment' => "\u{2067}c\u{202C}",
            'proposer_name' => "\u{FEFF}n\u{200B}",
            'video_ids' => [self::A],
        ])->assertSessionHasNoErrors();
        $p = Proposal::sole();
        $this->assertSame('abcd', $p->title);
        $this->assertSame("x\ny", $p->description);
        $this->assertSame('c', $p->comment);
        $this->assertSame('n', $p->proposer_name);
    }

    #[Group('mysql')]
    public function test_database_error_redirects_with_message_and_logs_no_bindings(): void
    {
        $this->fakeVideos([$this->videoResource(self::A, $this->id)]);
        $this->failQueriesOn('proposal_items');
        Log::spy();
        $this->store(['title' => 't', 'video_ids' => [self::A]])
            ->assertRedirect(route('channels.propose', $this->id))
            ->assertSessionHasErrors(['video_ids' => '保存に失敗しました。時間をおいて再度お試しください'])
            ->assertSessionHasInput('title', 't');
        $this->assertSame(0, Proposal::count());
        $this->assertNull(session('proposal_key_shown'));
        Log::shouldHaveReceived('error')->withArgs(function ($msg, $ctx = []) {
            $all = $msg.json_encode($ctx);

            return preg_match('/[0-9a-f]{64}/', $all) === 0 && ! str_contains($all, 'insert')
                && preg_match('/"sqlstate":"[0-9A-Z]{5}"/', json_encode($ctx)) === 1;
        })->once();
    }

    public function test_limiter_failure_after_save_still_shows_the_saved_proposal(): void
    {
        $this->fakeVideos([$this->videoResource(self::A, $this->id)]);
        $real = app(\Illuminate\Cache\RateLimiter::class);
        $limiter = Mockery::mock($real); // proxy: everything else goes to the real limiter
        $limiter->shouldReceive('hit')->andReturnUsing(function (string $key, int $decay = 60) use ($real) {
            if (str_starts_with($key, 'proposal-store:')) {
                throw new QueryException('mysql', 'update cache set value = ? where key = ?', ['secret-binding', 'k'], new \PDOException('SQLSTATE[HY000]: General error', 0));
            }

            return $real->hit($key, $decay);
        });
        RateLimiter::swap($limiter);
        Log::spy();

        $key = $this->keyFrom($this->store(['title' => 't', 'video_ids' => [self::A]]));
        $this->assertSame(1, Proposal::count());
        $this->get(route('proposals.shared', $key))->assertOk();
        Log::shouldHaveReceived('error')->withArgs(function ($msg, $ctx = []) {
            $all = $msg.json_encode($ctx);

            return $msg === 'Proposal save limiter failed' && array_keys($ctx) === ['sqlstate']
                && ! str_contains($all, 'secret-binding') && ! str_contains($all, 'update cache');
        })->once();
    }

    public function test_joined_emoji_survive_intact(): void
    {
        $family = "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}"; // 👨‍👩‍👧
        $this->fakeVideos([$this->videoResource(self::A, $this->id)]);
        $this->store([
            'title' => "家族 {$family}",
            'description' => "{$family}\n説明",
            'comment' => $family,
            'proposer_name' => $family,
            'video_ids' => [self::A],
        ])->assertSessionHasNoErrors();
        $p = Proposal::sole();
        $this->assertSame("家族 {$family}", $p->title);
        $this->assertSame("{$family}\n説明", $p->description);
        $this->assertSame($family, $p->comment);
        $this->assertSame($family, $p->proposer_name);
    }

    public function test_stale_channel_meta_is_refetched_at_save(): void
    {
        $this->travel(25)->days();
        $this->fakeChannel($this->id, ['title' => '新しい名前', 'thumbnail' => 'https://yt3.ggpht.com/new', 'handle' => '@NewHandle']);
        $this->fakeVideos([$this->videoResource(self::A, $this->id)]);

        $this->store(['title' => 't', 'video_ids' => [self::A]])->assertRedirect();

        $p = Proposal::sole();
        $this->assertSame('新しい名前', $p->channel_title);
        $this->assertSame('https://yt3.ggpht.com/new', $p->channel_thumbnail_url);
        $cache = ChannelCache::sole();
        $this->assertSame('新しい名前', $cache->title);
        $this->assertSame('https://yt3.ggpht.com/new', $cache->thumbnail_url);
        $this->assertSame('newhandle', $cache->handle);
        $this->assertTrue($cache->meta_fetched_at->equalTo(now()));
        // the list itself is not re-synced and its delete date is unchanged
        $this->assertTrue($cache->last_synced_at->equalTo(now()->subDays(25)));
        $this->assertTrue($cache->expires_at->equalTo(now()->addDays(5)));
        // channels.list (1) + videos.list (1)
        $this->assertSame(2, app(QuotaLedger::class)->used(QuotaLedger::READ));
    }

    public function test_fresh_channel_meta_is_not_refetched(): void
    {
        $this->travel(359)->minutes();
        $this->fakeVideos([$this->videoResource(self::A, $this->id)]);
        $this->store(['title' => 't', 'video_ids' => [self::A]])->assertRedirect();
        $this->assertSame('チャンネル A', Proposal::sole()->channel_title);
        Http::assertSentCount(1);
    }

    public function test_stale_meta_refetch_failure_fails_the_save(): void
    {
        $this->travel(25)->days();
        $this->fakeVideos([$this->videoResource(self::A, $this->id)]);

        app(QuotaLedger::class)->record(QuotaLedger::READ, 2000);
        $this->store(['title' => 't', 'video_ids' => [self::A]])
            ->assertRedirect(route('channels.propose', $this->id))
            ->assertSessionHasErrors(['video_ids' => '本日の取得上限に達したため、いまは保存できません。時間をおいて再度お試しください'])
            ->assertSessionHasInput('title', 't');
        $this->assertSame(0, Proposal::count());
        $this->assertNoApiCalls();

        DB::table('quota_usage')->delete();
        Http::swap(new Factory);
        $this->fakeYouTubeError('channels', 500, 'backendError');
        $this->fakeVideos([$this->videoResource(self::A, $this->id)]);
        $this->store(['title' => 't', 'video_ids' => [self::A]])
            ->assertSessionHasErrors(['video_ids' => 'YouTube との通信に失敗しました。時間をおいて再度お試しください']);

        Http::swap(new Factory);
        $this->fakeChannel($this->id, ['missing' => true]);
        $this->fakeVideos([$this->videoResource(self::A, $this->id)]);
        $this->store(['title' => 't', 'video_ids' => [self::A]])
            ->assertRedirect(route('channels.propose', $this->id))
            ->assertSessionHasErrors(['video_ids' => 'チャンネルが見つからないため保存できません']);
        $this->assertSame(0, Proposal::count());
        $this->assertSame('チャンネル A', ChannelCache::sole()->title);
    }

    public function test_case_insensitive_collation_still_uses_the_stored_channel_id(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Rebuilds the table with SQLite NOCASE to simulate a MySQL *_ci collation.');
        }
        // Simulate a MySQL case-insensitive collation: a route id differing only in case finds the row.
        $row = (array) DB::table('channel_caches')->first();
        Schema::disableForeignKeyConstraints();
        Schema::drop('channel_caches');
        DB::statement('CREATE TABLE channel_caches (channel_id varchar(24) COLLATE NOCASE PRIMARY KEY, handle varchar,'
            .' title varchar NOT NULL, thumbnail_url varchar, uploads_playlist_id varchar NOT NULL,'
            .' meta_fetched_at datetime NOT NULL, cached_since datetime NOT NULL, last_synced_at datetime NOT NULL,'
            .' truncated tinyint NOT NULL DEFAULT 0, expires_at datetime NOT NULL)');
        DB::table('channel_caches')->insert($row);
        Schema::enableForeignKeyConstraints();

        $this->fakeVideos([$this->videoResource(self::A, $this->id)]);
        $other = 'UC'.str_repeat('A', 22);
        $this->store(['title' => 't', 'video_ids' => [self::A]], $other)->assertRedirect();

        $p = Proposal::sole();
        $this->assertSame($this->id, $p->channel_id);
        $this->assertFalse($p->items[0]->is_outside_channel);
    }

    public function test_expired_csrf_token_returns_to_the_form_with_input(): void
    {
        $this->app->instance(PreventRequestForgery::class, new class($this->app, $this->app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        Http::fake();

        $this->post(route('proposals.store', $this->id), [
            '_token' => 'stale', 'title' => '残す', 'description' => 'd', 'video_ids' => [self::A], 'website' => '',
        ])
            ->assertRedirect(route('channels.propose', $this->id))
            ->assertSessionHasErrors(['save' => 'ページの有効期限が切れたため保存できませんでした。入力内容は残っているので、もう一度保存してください'])
            ->assertSessionHasInput('title', '残す')
            ->assertSessionHasInput('description', 'd')
            ->assertSessionHasInput('video_ids', [self::A]);
        $this->assertFalse(session()->hasOldInput('_token'));
        $this->assertFalse(session()->hasOldInput('website'));
        $this->assertSame(0, Proposal::count());
        $this->assertNoApiCalls();

        // cross-site: the plain 419 page
        $this->post(route('proposals.store', $this->id), ['_token' => 'stale', 'title' => 'x'], ['Sec-Fetch-Site' => 'cross-site'])
            ->assertStatus(419);
    }
}

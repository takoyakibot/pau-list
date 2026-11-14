<?php

namespace Tests\Feature;

use App\Models\ChannelCache;
use App\Models\Proposal;
use App\Services\YouTube\QuotaLedger;
use App\Support\ProposalKey;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Support\AssertsNoInlineCode;
use Tests\Support\CreatesProposals;
use Tests\Support\FakesYouTube;
use Tests\TestCase;

class OwnerEditScreenTest extends TestCase
{
    use AssertsNoInlineCode, CreatesProposals, FakesYouTube, RefreshDatabase;

    private const UPLOADS = 'UUaaaaaaaaaaaaaaaaaaaaaa';

    private const EDITOR_KEYS = ['description', 'draftId', 'items', 'lookupUrl', 'maxItems', 'privacy', 'remainingItems', 'resetLabel', 'title', 'unlistedUrl', 'videos'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-14 03:00:00'));
    }

    /** A reusable (fresh) channel list, so the catalog makes no API call. */
    private function cacheChannel(array $videos = []): void
    {
        $now = CarbonImmutable::now();
        ChannelCache::create([
            'channel_id' => $this->channelId,
            'handle' => 'a',
            'title' => 'チャンネル A',
            'thumbnail_url' => null,
            'uploads_playlist_id' => self::UPLOADS,
            'meta_fetched_at' => $now,
            'cached_since' => $now,
            'last_synced_at' => $now,
            'truncated' => false,
            'expires_at' => $now->addDays(30),
        ]);
        foreach ($videos as [$id, $title, $publishedAt]) {
            DB::table('channel_cache_videos')->insert([
                'channel_id' => $this->channelId,
                'video_id' => $id,
                'title' => $title,
                'thumbnail_url' => 'https://i.ytimg.com/vi/'.$id.'/mqdefault.jpg',
                'published_at' => $publishedAt,
                'fetched_at' => $now,
            ]);
        }
    }

    private function edit(string $key, string $title = 'My Channel'): TestResponse
    {
        return $this->actAsOwner($this->channelId, title: $title)->get(route('proposals.edit', $key));
    }

    /** @return array<string, mixed> */
    private function editorPayload(string $html): array
    {
        $this->assertSame(1, preg_match('/<div id="owner-editor"[^>]*\sdata-editor="([^"]*)"/', $html, $m));

        return json_decode(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_shows_needed_and_remaining_and_enabled_button(): void
    {
        $this->cacheChannel();
        $key = $this->createProposal();   // 2 本
        $res = $this->edit($key, 'チャンネル A')->assertOk();
        $res->assertSee('ログイン中: チャンネル A')
            ->assertSee('1 つの再生リストに入れられるのは最大 99 本')
            ->assertSee('この内容に必要な枠: 再生リスト 1 つ＋動画 2 本')
            ->assertSee('本日の残り: 再生リスト 1 つ＋動画あと 99 本まで作成できます')
            ->assertSee('あなたのチャンネルに再生リストを作成します')
            ->assertSee('押すとあなたの YouTube チャンネルに再生リストが作成されます（書き込み操作）。作成機能は準備中で、現在は押しても作成されません')
            ->assertSee('提案の内容に戻す')
            ->assertSee('データ提供: YouTube')
            ->assertSeeText('再生リストに入れる動画（2 / 99）')
            ->assertSee('動画 A')->assertSee('動画 B')
            ->assertDontSee('本減らすか');
        $this->assertDoesNotMatchRegularExpression('/<button[^>]*id="create-playlist"[^>]*disabled/', $res->getContent());
        // The only form is logout (creating is Phase 3).
        preg_match_all('/<form\b[^>]*>/i', $res->getContent(), $forms);
        $this->assertSame(['<form method="post" action="'.route('owner.logout').'" class="mt-2">'], $forms[0]);
        $this->assertNoInlineCode($res->getContent());
    }

    public function test_shortage_disables_button_and_shows_reset_time(): void
    {
        $this->cacheChannel();
        app(QuotaLedger::class)->record(QuotaLedger::WRITE, 4900);   // 空き 100 → N = 1
        $key = $this->createProposal();                              // n = 2
        $res = $this->edit($key)->assertOk();
        $res->assertSee('あと 1 本減らすか、リセット後に作成してください')
            ->assertSee('次にリセットされるのは 日本時間 11月14日 17時 です');
        $this->assertMatchesRegularExpression('/<button[^>]*id="create-playlist"[^>]*disabled/', $res->getContent());
        $payload = $this->editorPayload($res->getContent());
        $this->assertSame(1, $payload['remainingItems']);
        $this->assertSame('11月14日 17時', $payload['resetLabel']);
    }

    public function test_exhausted_quota_shows_reset_message(): void
    {
        $this->cacheChannel();
        app(QuotaLedger::class)->record(QuotaLedger::WRITE, 5000);
        $res = $this->edit($this->createProposal())->assertOk();
        $res->assertSee('本日はこれ以上作成できません。次にリセットされるのは 日本時間 11月14日 17時 です')
            ->assertDontSee('本減らすか');
        $this->assertMatchesRegularExpression('/<button[^>]*id="create-playlist"[^>]*disabled/', $res->getContent());
        $this->assertSame(0, $this->editorPayload($res->getContent())['remainingItems']);
    }

    public function test_proposal_with_no_items_disables_button(): void
    {
        $this->cacheChannel();
        $res = $this->edit($this->createProposal([], []))->assertOk();
        $res->assertSee('この内容に必要な枠: 再生リスト 1 つ＋動画 0 本');
        $this->assertMatchesRegularExpression('/<button[^>]*id="create-playlist"[^>]*disabled/', $res->getContent());
    }

    public function test_privacy_defaults_to_private_and_offers_three_values(): void
    {
        $this->cacheChannel();
        $html = $this->edit($this->createProposal())->getContent();
        $this->assertMatchesRegularExpression('/<select[^>]*name="privacy"/', $html);
        $this->assertMatchesRegularExpression('/<option value="private"[^>]*selected/', $html);
        foreach (['unlisted', 'public'] as $v) {
            $this->assertStringContainsString('<option value="'.$v.'"', $html);
            $this->assertDoesNotMatchRegularExpression('/<option value="'.$v.'"[^>]*selected/', $html);
        }
        $this->assertSame(3, substr_count($html, '<option value="'));
    }

    public function test_name_and_description_inputs_start_from_the_proposal(): void
    {
        $this->cacheChannel();
        $html = $this->edit($this->createProposal(['title' => '<b>名前</b>']))->getContent();
        $this->assertMatchesRegularExpression('/<input[^>]*name="title"[^>]*value="&lt;b&gt;名前&lt;\/b&gt;"/', $html);
        $this->assertMatchesRegularExpression('/<textarea[^>]*name="description"[^>]*>1 行目\n2 行目 https:\/\/example.com\/x<\/textarea>/', $html);
    }

    public function test_editor_payload_has_fixed_keys_and_no_secrets(): void
    {
        $this->cacheChannel([
            ['ccccccccccc', '一覧の動画 C', '2026-11-10 00:00:00'],
            ['ddddddddddd', '一覧の動画 D', '2026-11-12 00:00:00'],
        ]);
        $key = $this->createProposal([], [
            ['video_id' => 'bbbbbbbbbbb', 'title' => '動画 B', 'duration_seconds' => 10, 'is_outside_channel' => true],
            ['video_id' => 'aaaaaaaaaaa', 'title' => '動画 A', 'duration_seconds' => 10, 'is_outside_channel' => false],
        ]);
        $proposal = Proposal::query()->where('key_hash', ProposalKey::hash($key))->sole();
        $html = $this->edit($key)->assertOk()->getContent();
        $payload = $this->editorPayload($html);

        $keys = array_keys($payload);
        sort($keys);
        $this->assertSame(self::EDITOR_KEYS, $keys);
        $this->assertSame(99, $payload['maxItems']);
        $this->assertSame(99, $payload['remainingItems']);
        $this->assertSame('11月14日 17時', $payload['resetLabel']);
        $this->assertSame(route('channels.video', $this->channelId), $payload['lookupUrl']);
        $this->assertSame(substr($proposal->key_hash, 0, 16), $payload['draftId']);
        $this->assertSame([
            ['id' => 'bbbbbbbbbbb', 'title' => '動画 B', 'thumbnail_url' => 'https://i.ytimg.com/vi/bbbbbbbbbbb/mqdefault.jpg', 'outside' => true],
            ['id' => 'aaaaaaaaaaa', 'title' => '動画 A', 'thumbnail_url' => 'https://i.ytimg.com/vi/aaaaaaaaaaa/mqdefault.jpg', 'outside' => false],
        ], $payload['items']);
        $this->assertSame([
            ['id' => 'ddddddddddd', 'title' => '一覧の動画 D', 'thumbnail_url' => 'https://i.ytimg.com/vi/ddddddddddd/mqdefault.jpg', 'published_at' => '2026-11-12T00:00:00Z'],
            ['id' => 'ccccccccccc', 'title' => '一覧の動画 C', 'thumbnail_url' => 'https://i.ytimg.com/vi/ccccccccccc/mqdefault.jpg', 'published_at' => '2026-11-10T00:00:00Z'],
        ], $payload['videos']);
        $this->assertSame('私の再生リスト', $payload['title']);
        $this->assertSame("1 行目\n2 行目 https://example.com/x", $payload['description']);
        $this->assertSame('private', $payload['privacy']);

        $this->assertStringNotContainsString('ya29.session-token', $html);
        $this->assertStringNotContainsString($key, $html);
        $this->assertStringNotContainsString($proposal->key_hash, $html);
    }

    public function test_unlisted_videos_are_loaded_only_on_demand(): void
    {
        $this->cacheChannel([['ccccccccccc', '一覧の動画 C', '2026-11-10 00:00:00']]);
        $res = $this->edit($this->createProposal())->assertOk();
        // Rendering S5 never spends owner_read: the unlisted list is fetched by a button (owner.unlisted).
        $this->assertCount(0, Http::recorded());
        $this->assertSame(0, app(QuotaLedger::class)->used(QuotaLedger::OWNER_READ));
        $payload = $this->editorPayload($res->getContent());
        $this->assertSame(route('owner.unlisted'), $payload['unlistedUrl']);
        $this->assertSame(['ccccccccccc'], array_column($payload['videos'], 'id'));
        $this->assertMatchesRegularExpression('/<button type="button" id="load-unlisted"[^>]*>限定公開の動画も表示する<\/button>/', $res->getContent());
        $res->assertSee('あなたの限定公開の動画はあなたにだけ表示されます');
    }

    public function test_catalog_unavailable_still_renders_page(): void
    {
        config(['youtube.quota.read_budget' => 0]);
        $res = $this->edit($this->createProposal())->assertOk();
        $res->assertSee('チャンネルの動画一覧を読み込めませんでした。動画 URL で追加してください')
            ->assertSee('あなたのチャンネルに再生リストを作成します');
        $this->assertSame([], $this->editorPayload($res->getContent())['videos']);
        $this->assertCount(0, Http::recorded());
    }

    public function test_writes_nothing(): void
    {
        $this->cacheChannel();
        $key = $this->createProposal();
        $snapshot = fn () => [
            DB::table('proposals')->get()->map(fn ($r) => (array) $r)->all(),
            DB::table('proposal_items')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            app(QuotaLedger::class)->used(QuotaLedger::WRITE),
            DB::table('channel_caches')->get()->map(fn ($r) => (array) $r)->all(),
        ];
        $before = $snapshot();
        $this->edit($key)->assertOk();
        $this->assertEquals($before, $snapshot());
    }
}

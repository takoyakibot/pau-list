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

    private const EDITOR_KEYS = ['creationUrl', 'description', 'draftId', 'items', 'lookupUrl', 'maxItems', 'remainingItems', 'resetLabel', 'title', 'unlistedUrl', 'videos'];

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
            ->assertDontSee('（書き込み操作）')
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

    public function test_privacy_is_three_radios_with_none_checked(): void
    {
        $this->cacheChannel();
        $html = $this->edit($this->createProposal())->getContent();
        $this->assertStringNotContainsString('<select name="privacy"', $html);
        $this->assertMatchesRegularExpression('/<fieldset[^>]*>\s*<legend[^>]*>公開範囲（必須）<\/legend>/u', $html);
        $this->assertSame(3, preg_match_all('/<input type="radio" name="privacy"[^>]*>/', $html, $m));
        foreach (['private', 'unlisted', 'public'] as $i => $v) {
            $this->assertStringContainsString('value="'.$v.'" id="privacy-'.$v.'" x-model="privacy"', $m[0][$i]);
            $this->assertStringNotContainsString('checked', $m[0][$i]);
        }
        foreach ([
            ['非公開', '自分だけが見られます'],
            ['限定公開', 'リンクを知っている人だけが見られます'],
            ['公開', 'だれでも検索・閲覧できます'],
        ] as [$label, $hint]) {
            $this->assertMatchesRegularExpression('/>'.$label.'<\/span>\s*<span[^>]*>'.$hint.'<\/span>/u', $html);
        }
        // The client-side message for a missing choice sits next to the radios (property refs only, CSP build).
        $this->assertStringContainsString('<fieldset class="privacy-choice text-sm" aria-describedby="privacy-error">', $html);
        $this->assertStringContainsString('id="privacy-error" class="mt-1 text-sm text-danger" role="alert" x-show="hasPrivacyMessage" x-cloak x-text="privacyMessage"', $html);
    }

    public function test_select_filtered_button_starts_disabled_and_uses_property_refs(): void
    {
        $this->cacheChannel();
        $html = $this->edit($this->createProposal())->getContent();
        $this->assertMatchesRegularExpression('/<button type="button" @click="selectAllFiltered" :disabled="isSelectFilteredDisabled" x-text="selectFilteredLabel"[^>]* disabled>絞り込み結果をすべて選択<\/button>\s*<p class="select-filtered-status[^"]*" role="status" aria-live="polite" x-text="selectFilteredStatus"><\/p>/u', $html);
        $this->assertStringContainsString('x-model="query" x-ref="filter"', $html);
    }

    public function test_name_and_description_inputs_start_from_the_proposal(): void
    {
        $this->cacheChannel();
        $html = $this->edit($this->createProposal(['title' => '<b>名前</b>']))->getContent();
        $this->assertMatchesRegularExpression('/<input[^>]*name="title"[^>]*value="&lt;b&gt;名前&lt;\/b&gt;"/', $html);
        $this->assertMatchesRegularExpression('/<textarea[^>]*name="description"[^>]*>1 行目\n2 行目 https:\/\/example.com\/x<\/textarea>/', $html);
    }

    public function test_row_buttons_messages_use_property_refs_only(): void
    {
        $this->cacheChannel();
        $html = $this->edit($this->createProposal())->getContent();
        // Alpine CSP build: bindings are bare property/method names, never expressions.
        $this->assertStringContainsString(':aria-label="moveUpLabel"', $html);
        $this->assertStringContainsString(':aria-label="moveDownLabel"', $html);
        $this->assertStringContainsString(':aria-label="removeLabel"', $html);
        $this->assertStringContainsString('x-show="titleEmpty"', $html);
        $this->assertStringContainsString('再生リスト名を入力してください', $html);
        $this->assertStringContainsString('x-show="hasRestoredMessage" x-text="restoredMessage"', $html);
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
        // Relative to /p/{key}/edit (proposals.video, owner only): the raw key is never written into the page.
        $this->assertSame('videos/lookup', $payload['lookupUrl']);
        $this->assertStringContainsString('あなたの限定公開の動画は、URL を入力するか「限定公開の動画も表示する」から追加できます', $html);
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
        // No default privacy is sent (D46): the radios start with nothing chosen.
        $this->assertArrayNotHasKey('privacy', $payload);
        // Relative to /p/{key}/edit: the raw key is never written into the page.
        $this->assertSame('creation', $payload['creationUrl']);

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

    public function test_edit_redirects_to_creation_once_started(): void
    {
        $this->cacheChannel();
        $key = $this->createProposal();
        $this->makeCreation(2, [], key: $key);
        $this->edit($key)->assertRedirect(route('proposals.creation', $key));

        // Also once completed (spec 7.5: the edit screen cannot be opened again).
        $key = $this->createProposal();
        $this->makeCreation(2, ['status' => 'completed', 'completed_at' => now()], key: $key);
        $this->edit($key)->assertRedirect(route('proposals.creation', $key));
    }

    public function test_create_button_text_no_longer_says_pending(): void
    {
        $this->cacheChannel();
        $res = $this->edit($this->createProposal())->assertOk();
        $res->assertDontSee('準備中')
            ->assertDontSee('（書き込み操作）')
            ->assertSee('削除または非公開のため作成できません')
            ->assertSee('x-show="item.unavailable"', false);
        $this->assertSame('creation', $this->editorPayload($res->getContent())['creationUrl']);
        $this->assertMatchesRegularExpression('/<p[^>]*role="alert"[^>]*x-text="createMessage"/', $res->getContent());
    }

    public function test_cards_link_to_youtube(): void
    {
        $this->cacheChannel([['aaaaaaaaaaa', 'Video A', '2026-11-01 00:00:00']]);
        $html = $this->edit($this->createProposal())->assertOk()->getContent();
        $this->assertMatchesRegularExpression(
            '#<template x-for="video in filtered"[^>]*>\s*<li[^>]*>\s*<label[^>]*>.*?</label>\s*<a class="shrink-0 text-xs underline" :href="watchHref" x-show="watchHref" rel="noopener noreferrer" target="_blank">YouTube で見る</a>\s*</li>#s',
            $html,
        );
        $this->assertStringContainsString('データ提供: YouTube', $html);
        $this->assertNoInlineCode($html);
    }
}

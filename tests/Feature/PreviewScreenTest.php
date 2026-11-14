<?php

namespace Tests\Feature;

use App\Models\PlaylistCreation;
use App\Models\Proposal;
use App\Services\YouTube\QuotaLedger;
use App\Support\OwnerLogin;
use App\Support\OwnerSession;
use App\Support\ProposalKey;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Tests\Support\AssertsNoInlineCode;
use Tests\TestCase;

class PreviewScreenTest extends TestCase
{
    use AssertsNoInlineCode, RefreshDatabase;

    private string $channelId;

    protected function setUp(): void
    {
        parent::setUp();
        // 2026-11-14 03:00Z = 11月14日 12時 JST
        $this->travelTo(CarbonImmutable::parse('2026-11-14 03:00:00'));
        $this->channelId = 'UC'.str_repeat('a', 22);
    }

    /** @param array<string, mixed> $attrs */
    private function makeProposal(array $attrs = [], ?array $items = null): string
    {
        $key = ProposalKey::generate();
        $items ??= [
            ['video_id' => 'aaaaaaaaaaa', 'title' => '動画 A', 'duration_seconds' => 65, 'is_outside_channel' => false],
            ['video_id' => 'bbbbbbbbbbb', 'title' => '動画 B', 'duration_seconds' => 3658, 'is_outside_channel' => true],
        ];
        $proposal = Proposal::create(array_merge([
            'key_hash' => ProposalKey::hash($key),
            'channel_id' => $this->channelId,
            'channel_title' => 'チャンネル A',
            'channel_thumbnail_url' => null,
            'title' => '私の再生リスト',
            'description' => "1 行目\n2 行目 https://example.com/x",
            'comment' => null,
            'proposer_name' => null,
            'item_count' => count($items),
            'created_at' => now(),
            'expires_at' => now()->addDays(30),
        ], $attrs));
        foreach ($items as $i => $item) {
            $outside = $item['is_outside_channel'];
            $proposal->items()->create(array_merge([
                'position' => $i + 1,
                'video_channel_id' => $outside ? 'UC'.str_repeat('b', 22) : $this->channelId,
                'video_channel_title' => $outside ? '別チャンネル' : 'チャンネル A',
                'thumbnail_url' => 'https://i.ytimg.com/vi/'.$item['video_id'].'/mqdefault.jpg',
                'published_at' => now()->subDays(3),
            ], $item));
        }

        return $key;
    }

    public function test_renders_proposal_escaped_with_badges_and_limits(): void
    {
        $key = $this->makeProposal([
            'title' => '<script>alert(1)</script>',
            'comment' => '<b>x</b> https://spam.example',
        ]);

        $res = $this->get(route('proposals.show', $key))->assertOk();
        $res->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
        $res->assertDontSee('<script>alert(1)', false);
        $res->assertDontSee('<b>x</b>', false);
        $res->assertSee('&lt;b&gt;x&lt;/b&gt; https://spam.example', false);
        $res->assertDontSee('href="https://spam.example', false);
        $res->assertDontSee('href="https://example.com/x', false);
        $res->assertSee('whitespace-pre-line', false);
        $res->assertSee('対象チャンネル外');
        $res->assertSee('2 本');
        $res->assertSee('合計 1 時間 2 分');
        $res->assertSee('1:05');
        $res->assertSee('1:00:58');
        $res->assertSee('href="https://www.youtube.com/watch?v=aaaaaaaaaaa"', false);
        $res->assertSee('href="https://www.youtube.com/watch?v=bbbbbbbbbbb"', false);
        $res->assertSee('src="https://i.ytimg.com/vi/aaaaaaaaaaa/mqdefault.jpg"', false);
        $res->assertSee('別チャンネル');
        $res->assertSee('匿名');
        $res->assertSee('作成日 11月14日／12月14日までに削除されます');
        $res->assertSee('未作成');
        $res->assertSee('1 つの再生リストに入れられるのは最大 99 本');
        $res->assertSee('本日の残り: 再生リスト 1 つ＋動画あと 99 本まで作成できます');
        $res->assertDontSee('本日の残りを超えています');
        $res->assertSee('データ提供: YouTube');
        $res->assertDontSee('準備中');
        $res->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
        $this->assertNoInlineCode($res->getContent());
    }

    public function test_shows_proposer_name_and_items_in_position_order(): void
    {
        $key = $this->makeProposal(['proposer_name' => 'ななし', 'comment' => "よろしく\nお願いします"]);
        $res = $this->get(route('proposals.show', $key))->assertOk();
        $res->assertSee('ななし');
        $res->assertDontSee('匿名');
        $res->assertSeeInOrder(['よろしく', 'お願いします']);
        $res->assertSeeInOrder(['動画 A', '動画 B']);
    }

    public function test_total_notes_videos_with_unknown_duration(): void
    {
        $key = $this->makeProposal([], [
            ['video_id' => 'aaaaaaaaaaa', 'title' => '動画 A', 'duration_seconds' => 65, 'is_outside_channel' => false],
            ['video_id' => 'bbbbbbbbbbb', 'title' => '動画 B', 'duration_seconds' => null, 'is_outside_channel' => false],
        ]);
        $this->get(route('proposals.show', $key))->assertOk()
            ->assertSee('合計 1 分（長さ不明の動画を除く）');

        $known = $this->makeProposal();
        $this->get(route('proposals.show', $known))->assertOk()
            ->assertSee('合計 1 時間 2 分')
            ->assertDontSee('長さ不明');
    }

    public function test_shows_reset_time_when_items_exceed_remaining(): void
    {
        // 5000 - 4851 = 149 units → floor((149 - 50) / 50) = 1 item left
        app(QuotaLedger::class)->record(QuotaLedger::WRITE, 4851);
        $key = $this->makeProposal();

        $res = $this->get(route('proposals.show', $key))->assertOk();
        $res->assertSee('本日の残り: 再生リスト 1 つ＋動画あと 1 本まで作成できます');
        // 2026-11-13 19:00 PST → next PT midnight = 2026-11-14 08:00Z = 11月14日 17時 JST
        $res->assertSee('この提案（動画 2 本）は本日の残りを超えています。次にリセットされるのは 日本時間 11月14日 17時 です');
    }

    public function test_missing_and_expired_keys_return_identical_404(): void
    {
        $expired = $this->makeProposal(['expires_at' => now()]);

        $a = $this->get('/p/'.$expired)->assertNotFound();
        $b = $this->get('/p/'.ProposalKey::generate())->assertNotFound();
        $c = $this->get('/p/'.str_repeat('a', 42).'!')->assertNotFound();

        $a->assertSee('ページが見つかりません');
        $a->assertSee('提案は作成から 30 日以内に削除されます');
        $a->assertDontSee('過ぎると');
        $a->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->assertStringContainsString('no-store', (string) $a->headers->get('Cache-Control'));
        $this->assertSame($a->getContent(), $b->getContent());
        $this->assertSame($a->getContent(), $c->getContent());
    }

    public function test_database_error_shows_japanese_page_without_logging_key_hash(): void
    {
        $key = $this->makeProposal();
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
            $logged[] = $e->message.' '.json_encode($e->context);
        });
        Schema::drop('proposal_items');
        Schema::drop('proposals');

        foreach ([route('proposals.show', $key), route('proposals.shared', $key)] as $url) {
            $res = $this->withSession(['proposal_key_shown' => ProposalKey::hash($key)])->get($url);
            $res->assertStatus(503);
            $res->assertSee('しばらくしてからお試しください');
            $res->assertDontSee(ProposalKey::hash($key));
        }

        $this->assertNotEmpty($logged);
        foreach ($logged as $line) {
            $this->assertDoesNotMatchRegularExpression('/[0-9a-f]{64}/', $line);
            $this->assertStringNotContainsString($key, $line);
        }
    }

    private function actAsOwner(string $channelId, string $title): static
    {
        return $this->withSession([OwnerSession::OWNER_KEY => OwnerSession::serialize(
            new OwnerLogin('ya29.session-token', now()->toImmutable()->addMinutes(30), $channelId, $title),
        )]);
    }

    public function test_preview_shows_login_link_consent_and_warning(): void
    {
        $key = $this->makeProposal();
        $res = $this->get(route('proposals.show', $key))->assertDontSee('準備中')
            ->assertSee('href="'.e(route('owner.login', ['p' => $key])).'"', false)
            ->assertSee('チャンネル主の方: Google でログインして作成へ')
            ->assertSeeText('利用規約・プライバシーポリシーに同意のうえログインしてください')
            ->assertSee('確認されていないアプリの警告が表示されます。「詳細」→「List Maker（安全ではないページ）に移動」で続行できます')
            ->assertSee('YouTube の管理権限を求めますが、使うのはチャンネルの確認・自分の限定公開動画の表示・再生リストの作成と動画の追加だけです')
            ->assertSee('href="'.route('terms').'"', false)->assertSee('href="'.route('privacy').'"', false)
            ->assertDontSee('ログイン中:')
            ->assertDontSee('href="'.route('proposals.edit', $key).'"', false);
        $this->assertNoInlineCode($res->getContent());
    }

    public function test_preview_links_to_edit_for_matching_owner_only(): void
    {
        $key = $this->makeProposal();
        $this->actAsOwner($this->channelId, '<i>自分</i>')->get(route('proposals.show', $key))
            ->assertSee('href="'.route('proposals.edit', $key).'"', false)
            ->assertSee('編集画面へ進む')
            ->assertSee('ログイン中: &lt;i&gt;自分&lt;/i&gt;', false)
            ->assertDontSee('ya29.session-token');

        $this->actAsOwner('UC'.str_repeat('z', 22), '他人')->get(route('proposals.show', $key))
            ->assertDontSee('href="'.route('proposals.edit', $key).'"', false)
            ->assertDontSee('編集画面へ進む')
            ->assertSee('別のチャンネルでログインし直す')
            ->assertDontSee('チャンネル主の方: Google でログインして作成へ')
            ->assertSee('href="'.e(route('owner.login', ['p' => $key])).'"', false);
    }

    public function test_expired_owner_sees_login_link(): void
    {
        $key = $this->makeProposal();
        $this->withSession([OwnerSession::OWNER_KEY => OwnerSession::serialize(
            new OwnerLogin('ya29.session-token', now()->toImmutable(), $this->channelId, '自分'),
        )])->get(route('proposals.show', $key))
            ->assertDontSee('編集画面へ進む')
            ->assertSee('チャンネル主の方: Google でログインして作成へ');
    }

    public function test_preview_shows_owner_flash_messages_as_alerts(): void
    {
        $key = $this->makeProposal();
        $this->withSession(['owner_notice' => '<b>通知</b>'])->get(route('proposals.show', $key))
            ->assertSee('role="alert"', false)->assertSee('&lt;b&gt;通知&lt;/b&gt;', false);
        $this->withSession(['owner_error' => 'エラー文'])->get(route('proposals.show', $key))
            ->assertSee('role="alert"', false)->assertSee('エラー文');
    }

    /** @param array<string, mixed> $attrs */
    private function makeCreationFor(string $key, array $attrs = []): PlaylistCreation
    {
        $proposal = Proposal::where('key_hash', ProposalKey::hash($key))->sole();
        $creation = PlaylistCreation::create(array_merge([
            'proposal_id' => $proposal->id,
            'owner_channel_id' => $proposal->channel_id,
            'title' => $proposal->title,
            'description' => '',
            'privacy_status' => 'private',
            'status' => PlaylistCreation::ADDING,
            'estimated_units' => 150,
            'started_at' => now(),
        ], $attrs));
        $creation->items()->create([
            'position' => 0, 'video_id' => 'aaaaaaaaaaa', 'title' => '動画 A', 'thumbnail_url' => null,
            'is_outside_channel' => false, 'status' => 'done',
        ]);

        return $creation;
    }

    private function completed(string $privacy, string $playlistId): array
    {
        return ['status' => PlaylistCreation::COMPLETED, 'privacy_status' => $privacy, 'youtube_playlist_id' => $playlistId, 'completed_at' => now()];
    }

    public function test_status_and_link_for_public_playlist_are_shown_to_anyone(): void
    {
        $key = $this->makeProposal();
        $this->makeCreationFor($key, $this->completed('public', 'PLpub'));
        $res = $this->get(route('proposals.show', $key))->assertOk()
            ->assertSee('状態: 作成済み')
            ->assertDontSee('状態: 未作成')
            ->assertSee('<a class="font-semibold underline" href="https://www.youtube.com/playlist?list=PLpub" rel="noopener noreferrer" target="_blank">作成された再生リストを YouTube で見る</a>', false)
            // The remaining-today section is only for proposals not created yet.
            ->assertDontSee('本日の残り:');
        $this->assertNoInlineCode($res->getContent());

        $this->actAsOwner('UC'.str_repeat('z', 22), '他人')->get(route('proposals.show', $key))
            ->assertSee('href="https://www.youtube.com/playlist?list=PLpub"', false);
    }

    public function test_unlisted_or_private_playlist_link_only_for_matching_owner(): void
    {
        foreach (['unlisted' => 'PLunl', 'private' => 'PLprv'] as $privacy => $id) {
            $key = $this->makeProposal();
            $this->makeCreationFor($key, $this->completed($privacy, $id));

            $html = $this->get(route('proposals.show', $key))->assertOk()->assertSee('状態: 作成済み')->getContent();
            $this->assertStringNotContainsString($id, $html, $privacy);
            $this->assertStringNotContainsString('作成された再生リストを YouTube で見る', $html, $privacy);

            $html = $this->actAsOwner('UC'.str_repeat('z', 22), '他人')->get(route('proposals.show', $key))->assertSee('状態: 作成済み')->getContent();
            $this->assertStringNotContainsString($id, $html, $privacy);

            $this->actAsOwner($this->channelId, '自分')->get(route('proposals.show', $key))
                ->assertSee('状態: 作成済み')
                ->assertSee('href="https://www.youtube.com/playlist?list='.$id.'"', false)
                ->assertSee('作成された再生リストを YouTube で見る');
            $this->flushSession();
        }
    }

    public function test_creating_status_hides_remaining_and_owner_sees_progress_link(): void
    {
        $key = $this->makeProposal();
        $this->makeCreationFor($key, ['status' => PlaylistCreation::PAUSED, 'pause_reason' => PlaylistCreation::PAUSE_USER, 'youtube_playlist_id' => 'PLmid', 'privacy_status' => 'public']);

        $html = $this->get(route('proposals.show', $key))->assertOk()
            ->assertSee('状態: 作成中')
            ->assertDontSee('本日の残り:')
            ->assertDontSee('href="'.route('proposals.creation', $key).'"', false)
            ->getContent();
        // No link while creating, even for a public playlist.
        $this->assertStringNotContainsString('PLmid', $html);

        $this->actAsOwner($this->channelId, '自分')->get(route('proposals.show', $key))
            ->assertSee('href="'.route('proposals.creation', $key).'"', false)
            ->assertSee('作成状況を見る')
            ->assertDontSee('編集画面へ進む')
            ->assertDontSee('href="'.route('proposals.edit', $key).'"', false);
    }

    public function test_not_created_status_after_abort_without_playlist(): void
    {
        $key = $this->makeProposal();
        $this->makeCreationFor($key, ['status' => PlaylistCreation::COMPLETED, 'privacy_status' => 'public', 'completed_at' => now()]);
        $this->get(route('proposals.show', $key))->assertOk()
            ->assertSee('状態: 再生リストは作成されませんでした')
            ->assertDontSee('作成された再生リストを YouTube で見る')
            ->assertDontSee('本日の残り:');
    }

    public function test_owner_unlisted_videos_never_appear_on_preview(): void
    {
        $key = $this->makeProposal();
        $creation = $this->makeCreationFor($key, $this->completed('public', 'PLpub'));
        $creation->items()->create([
            'position' => 1, 'video_id' => 'uNlIsTeD_01', 'title' => '限定公開の秘密の動画', 'thumbnail_url' => null,
            'is_outside_channel' => false, 'status' => 'done',
        ]);

        foreach ([null, $this->channelId] as $as) {
            if ($as !== null) {
                $this->actAsOwner($as, '自分');
            }
            $html = $this->get(route('proposals.show', $key))->assertOk()->getContent();
            $this->assertStringNotContainsString('uNlIsTeD_01', $html);
            $this->assertStringNotContainsString('限定公開の秘密の動画', $html);
        }
    }
}

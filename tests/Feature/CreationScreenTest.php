<?php

namespace Tests\Feature;

use App\Models\PlaylistCreation;
use App\Models\PlaylistCreationItem;
use App\Services\YouTube\QuotaLedger;
use App\Support\QuotaClock;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\AssertsNoInlineCode;
use Tests\Support\CreatesProposals;
use Tests\TestCase;

/** S6 (spec 2.5, 7.4, 7.5): the owner's progress page. */
#[Group('mysql')]
class CreationScreenTest extends TestCase
{
    use AssertsNoInlineCode, CreatesProposals, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-15 03:00:00'));
    }

    /** @return array<string, mixed> */
    private function runner(TestResponse $res): array
    {
        preg_match('/data-runner="([^"]*)"/', $res->getContent(), $m);

        return json_decode(html_entity_decode($m[1] ?? '', ENT_QUOTES), true) ?? [];
    }

    private function setItem(PlaylistCreation $c, int $position, array $attrs): void
    {
        $c->items()->where('position', $position)->sole()->update($attrs);
    }

    public function test_shows_progress_items_and_buttons_for_owner(): void
    {
        $key = $this->createProposal();
        $c = $this->makeCreation(3, ['status' => PlaylistCreation::ADDING, 'youtube_playlist_id' => 'PLx', 'title' => '作る再生リスト'], 150, $key);
        $this->setItem($c, 0, ['status' => PlaylistCreationItem::DONE, 'youtube_item_id' => 'I0']);
        $this->setItem($c, 1, ['last_error' => 'transient']);

        $res = $this->actAsOwner($this->channelId, title: 'わたしのチャンネル', token: 'ya29.secret-owner-token')
            ->get("/p/$key/creation")->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('再生リストの作成')
            ->assertSee('作る再生リスト')
            ->assertSee('ログイン中: わたしのチャンネル')
            ->assertSee('ログアウト（Google 側の承認も取り消します）')
            ->assertSee('1 / 3 本を処理しました（追加 1・失敗 0・中止 0）')
            ->assertSee('<progress', false)
            ->assertSee('role="status"', false)
            ->assertSeeInOrder(['動画 0', '追加済み', '動画 1', '待機中', 'YouTube との通信に失敗しました', '動画 2', '待機中'])
            ->assertSee('一時停止')
            ->assertSee('続きから')
            ->assertSee('残りを中止')
            ->assertSee('失敗分を再試行（必要な枠: 動画 0 本）')
            ->assertSee('見つからなかったので作り直す')
            ->assertSee('本日の残り: 再生リスト 1 つ＋動画あと 96 本まで作成できます')
            ->assertSee('もう一度ログインする')
            ->assertSee('Google アカウントの')
            ->assertSee('https://myaccount.google.com/permissions')
            ->assertSee('データ提供: YouTube')
            ->assertSee('href="https://www.youtube.com/playlist?list=PLx"', false)
            ->assertDontSee($key)
            ->assertDontSee('ya29');
        $this->assertNoInlineCode($res->getContent());

        $runner = $this->runner($res);
        $this->assertSame(['progress', 'autostart', 'urls', 'titles'], array_keys($runner));
        $this->assertSame([
            'step' => 'creation/step', 'pause' => 'creation/pause', 'resume' => 'creation/resume',
            'abort' => 'creation/abort', 'retry' => 'creation/retry', 'recreate' => 'creation/recreate',
        ], $runner['urls']);
        $this->assertFalse($runner['autostart']);
        $this->assertSame(['動画 0', '動画 1', '動画 2'], $runner['titles']);
        $this->assertSame(['adding', 1, 3], [$runner['progress']['status'], $runner['progress']['done'], $runner['progress']['total']]);
        Http::assertNothingSent();
    }

    public function test_shows_how_to_change_visibility_unless_public(): void
    {
        $tail = '」で作成されます。公開範囲は、作成後に YouTube Studio の「再生リスト」で変更できます。';
        foreach (['private' => '非公開', 'unlisted' => '限定公開'] as $privacy => $label) {
            $key = $this->createProposal();
            $this->makeCreation(1, ['privacy_status' => $privacy], null, $key);
            $this->actAsOwner($this->channelId)->get("/p/$key/creation")->assertOk()
                ->assertSee('この再生リストは「'.$label.$tail);
        }

        $key = $this->createProposal();
        $this->makeCreation(1, ['privacy_status' => 'public'], null, $key);
        $this->actAsOwner($this->channelId)->get("/p/$key/creation")->assertOk()
            ->assertDontSee('公開範囲は、作成後に')
            ->assertDontSee('で作成されます');
    }

    public function test_without_creation_redirects_to_edit(): void
    {
        $key = $this->createProposal();
        $this->actAsOwner($this->channelId)->get("/p/$key/creation")->assertRedirect(route('proposals.edit', $key));
    }

    public function test_not_logged_in_redirects_to_preview_and_mismatch_is_403(): void
    {
        $key = $this->createProposal();
        $c = $this->makeCreation(2, key: $key);

        $this->get("/p/$key/creation")->assertRedirect(route('proposals.show', $key))->assertSessionHas('owner_notice');
        $this->actAsOwner($this->channelId, now()->toImmutable()->subSecond())->get("/p/$key/creation")->assertRedirect(route('proposals.show', $key));

        $this->actAsOwner('UC'.str_repeat('z', 22))->get("/p/$key/creation")->assertForbidden()
            ->assertSee('ログイン中のチャンネルはこの提案の対象ではありません')->assertDontSee('動画 0');

        // The creation's own channel is checked as well (spec 6.4).
        $c->update(['owner_channel_id' => 'UC'.str_repeat('y', 22)]);
        $this->actAsOwner($this->channelId)->get("/p/$key/creation")->assertForbidden()->assertDontSee('動画 0');

        // A page only: not as a fetch, image or iframe.
        $c->update(['owner_channel_id' => $this->channelId]);
        $this->actAsOwner($this->channelId)->get("/p/$key/creation", ['Sec-Fetch-Mode' => 'cors', 'Sec-Fetch-Dest' => 'empty'])->assertStatus(400);
    }

    public function test_autostart_flag_only_right_after_start(): void
    {
        $key = $this->createProposal();
        $this->makeCreation(2, reserve: 150, key: $key);

        $this->actAsOwner($this->channelId);
        session()->flash('creation_autostart', true); // as CreationController::start does,
        session()->ageFlashData(); // and as the end of that start request does
        $first = $this->get("/p/$key/creation")->assertOk();
        $this->assertTrue($this->runner($first)['autostart']);

        // Flash data is gone on the next load (a reload does not start the steps by itself).
        $this->assertFalse($this->runner($this->get("/p/$key/creation")->assertOk())['autostart']);
    }

    public function test_completed_shows_playlist_link_for_owner_even_when_private(): void
    {
        $key = $this->createProposal();
        $c = $this->makeCreation(3, ['status' => PlaylistCreation::COMPLETED, 'privacy_status' => 'private', 'youtube_playlist_id' => 'PLpriv', 'completed_at' => now()], key: $key);
        $this->setItem($c, 0, ['status' => PlaylistCreationItem::DONE]);
        $this->setItem($c, 1, ['status' => PlaylistCreationItem::FAILED, 'last_error' => 'videoNotFound']);
        $this->setItem($c, 2, ['status' => PlaylistCreationItem::FAILED, 'last_error' => 'forbidden']);

        $this->actAsOwner($this->channelId)->get("/p/$key/creation")->assertOk()
            ->assertSee('href="https://www.youtube.com/playlist?list=PLpriv"', false)
            ->assertSee('作成が完了しました（追加 1 本・失敗 2 本・中止 0 本）')
            ->assertSee('動画が見つかりません（削除または非公開）')
            ->assertSee('この動画は追加できません')
            ->assertSee('失敗分を再試行（必要な枠: 動画 2 本）')
            ->assertSee('href="delete"', false)
            ->assertSee('この提案を削除');
    }

    public function test_unlisted_item_titles_are_shown_only_here(): void
    {
        $key = $this->createProposal();
        $c = $this->makeCreation(2, ['status' => PlaylistCreation::ADDING, 'youtube_playlist_id' => 'PLx'], 100, $key);
        $this->setItem($c, 1, ['title' => '限定公開のひみつ動画', 'video_id' => 'unlistedV01']);

        $this->actAsOwner($this->channelId)->get("/p/$key/creation")->assertOk()->assertSee('限定公開のひみつ動画');

        $this->app['session']->flush();
        $this->get("/p/$key")->assertOk()->assertDontSee('限定公開のひみつ動画')->assertDontSee('unlistedV01');
    }

    public function test_paused_quota_shows_remaining_and_reset_time(): void
    {
        $key = $this->createProposal();
        $c = $this->makeCreation(2, ['status' => PlaylistCreation::PAUSED, 'pause_reason' => PlaylistCreation::PAUSE_QUOTA_BUDGET, 'youtube_playlist_id' => 'PLx'], key: $key);
        DB::table('quota_usage')->insert(['day_pt' => QuotaClock::todayPt(), 'bucket' => QuotaLedger::WRITE, 'units' => (int) config('youtube.quota.write_limit')]);

        $this->actAsOwner($this->channelId)->get("/p/$key/creation")->assertOk()
            ->assertSee('本日の作成枠が足りないため一時停止しました。本日はこれ以上作成できません。次にリセットされるのは 日本時間 11月15日 17時 です');

        $c->update(['pause_reason' => PlaylistCreation::PAUSE_QUOTA_EXCEEDED]);
        $this->get("/p/$key/creation")->assertOk()
            ->assertSee('YouTube 側の本日の上限に達したため一時停止しました。リセット後に「続きから」を押してください（次にリセットされるのは 日本時間 11月15日 17時 です）');
    }

    // ---- final review (UX m4, m5, m6, m7) ----

    public function test_auth_expired_pause_tells_a_logged_in_owner_to_press_resume(): void
    {
        $key = $this->createProposal();
        $this->makeCreation(2, ['status' => PlaylistCreation::PAUSED, 'pause_reason' => PlaylistCreation::PAUSE_AUTH, 'youtube_playlist_id' => 'PLx'], 100, $key);

        $res = $this->actAsOwner($this->channelId)->get("/p/$key/creation")->assertOk()
            ->assertSee('ログインし直しました。「続きから」を押してください')
            ->assertDontSee('ログインの有効期限が切れたため');
        $this->assertSame('ログインし直しました。「続きから」を押してください', $this->runner($res)['progress']['message']);
    }

    public function test_progress_bar_label_and_two_step_abort_markup(): void
    {
        $key = $this->createProposal();
        $this->makeCreation(2, ['status' => PlaylistCreation::ADDING, 'youtube_playlist_id' => 'PLx'], 100, $key);

        $this->actAsOwner($this->channelId)->get("/p/$key/creation")->assertOk()
            ->assertSee('aria-label="作成の進み具合"', false)
            ->assertSee('@click="onAbort" x-text="abortLabel"', false)
            ->assertSee('@click="onAbortCancel"', false)
            ->assertSee('x-show="showAbortWarning"', false)
            ->assertSee('中止すると、まだ追加していない動画は追加されません。元に戻せません（失敗した動画の再試行だけはできます）');
    }

    public function test_completed_without_playlist_points_to_deleting_and_starting_over(): void
    {
        $hint = '作り直すには、「この提案を削除」で提案を削除してから、もう一度提案を作成してください';
        $key = $this->createProposal();
        $this->makeCreation(1, ['status' => PlaylistCreation::COMPLETED, 'completed_at' => now()], key: $key);
        $html = $this->actAsOwner($this->channelId)->get("/p/$key/creation")->assertOk()->assertSee($hint)->getContent();
        $this->assertMatchesRegularExpression('/<p class="mt-2" x-show="showRemakeHint"\s*>/', $html); // visible without JS

        // With a playlist the hint stays hidden (x-cloak) until the script decides.
        $key2 = $this->createProposal();
        $this->makeCreation(1, ['status' => PlaylistCreation::COMPLETED, 'youtube_playlist_id' => 'PLx', 'completed_at' => now()], key: $key2);
        $this->get("/p/$key2/creation")->assertOk()->assertSee('x-show="showRemakeHint"  x-cloak', false);
    }
}

<?php

namespace Tests\Feature;

use App\Services\YouTube\QuotaLedger;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoChannelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Support\AssertsNoInlineCode;
use Tests\Support\FakesYouTube;
use Tests\TestCase;

class ProposeScreenTest extends TestCase
{
    use AssertsNoInlineCode, FakesYouTube, RefreshDatabase;

    private function id(string $c = 'a'): string
    {
        return 'UC'.str_repeat($c, 22);
    }

    private function setUpChannel(string $c = 'a', int $videos = 3, array $o = []): string
    {
        $id = $this->id($c);
        $this->fakeChannel($id, $o);
        $items = [];
        for ($i = 0; $i < $videos; $i++) {
            $items[] = $this->uploadItem('vid'.$c.str_pad((string) $i, 7, '0', STR_PAD_LEFT), '2026-01-'.sprintf('%02d', 20 - $i).'T00:00:00Z');
        }
        $this->fakeUploads('UU'.substr($id, 2), [$items]);

        return $id;
    }

    private function catalogPayload(TestResponse $res): array
    {
        $this->assertSame(1, preg_match('/data-catalog="([^"]*)"/', $res->getContent(), $m));

        return json_decode(html_entity_decode($m[1]), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_shows_list_limits_remaining_and_expiry(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-14 03:00:00'));
        $id = $this->setUpChannel();
        $res = $this->get(route('channels.propose', $id))->assertOk();
        $res->assertSee('1 つの再生リストに入れられるのは最大 99 本');
        $res->assertSee('本日の残り: 再生リスト 1 つ＋動画あと 99 本まで作成できます');
        $res->assertSee('作成はチャンネル主が操作した時点の残りで決まります');
        $res->assertSee('この一覧は 11月14日に取り込み、12月14日までに削除されます（削除後に開くと全件を取り込み直します）');
        $res->assertSee('一覧の最終取り込み: 11月14日 12時00分');
        $res->assertSee('データ提供: YouTube');
        $res->assertSee('最近公開に切り替えた古い動画などは一覧に出ないことがあります。動画 URL で追加してください');
        $res->assertSee('https://www.youtube.com/channel/'.$id, false);
        $res->assertDontSee('前回の一覧は 30 日の保存期限で削除');
        $res->assertDontSee('これより古い動画');
        $this->assertNoInlineCode($res->getContent());
        $payload = $this->catalogPayload($res);
        $this->assertSame(99, $payload['maxItems']);
        $this->assertSame($id, $payload['channelId']);
        $this->assertSame(route('channels.video', $id), $payload['lookupUrl']);
        $this->assertSame([], $payload['unavailableIds']);
        $this->assertCount(3, $payload['videos']);
        $this->assertSame('2026-01-20T00:00:00Z', $payload['videos'][0]['published_at']);
        $this->assertSame(['id', 'title', 'thumbnail_url', 'published_at'], array_keys($payload['videos'][0]));
    }

    public function test_title_is_escaped_in_page_and_payload(): void
    {
        $id = $this->id();
        $this->fakeChannel($id, ['title' => '<b>x</b>']);
        $this->fakeUploads('UU'.substr($id, 2), [[$this->uploadItem('abcdefghijk', '2026-01-01T00:00:00Z', 'public', '"><img src=x>')]]);
        $res = $this->get(route('channels.propose', $id))->assertOk();
        $this->assertStringNotContainsString('<b>x</b>', $res->getContent());
        $this->assertStringNotContainsString('<img src=x>', $res->getContent());
        $this->assertSame('"><img src=x>', $this->catalogPayload($res)['videos'][0]['title']);
    }

    public function test_reimport_after_expiry_shows_notice_once(): void
    {
        $id = $this->setUpChannel();
        DB::table('channel_cache_expirations')->insert(['channel_id' => $id, 'expired_at' => now()->subDay()]);
        $this->get(route('channels.propose', $id))->assertOk()
            ->assertSee('前回の一覧は 30 日の保存期限で削除されたため、全件を取り込み直しています');
        $this->assertSame(0, DB::table('channel_cache_expirations')->count());
        $this->get(route('channels.propose', $id))->assertOk()
            ->assertDontSee('取り込み直しています');
    }

    public function test_budget_exhausted_shows_cached_list_with_marker(): void
    {
        $id = $this->setUpChannel();
        $this->get(route('channels.propose', $id))->assertOk();
        $this->travel(7)->hours();
        app(QuotaLedger::class)->record(QuotaLedger::READ, 2000);
        $res = $this->get(route('channels.propose', $id))->assertOk();
        $res->assertSee('（本日の取得上限に達したため更新していません）');
        $res->assertSee('この一覧は');
        $this->assertCount(3, $this->catalogPayload($res)['videos']);
    }

    public function test_failed_refresh_shows_cached_list_with_marker(): void
    {
        $id = $this->setUpChannel();
        $this->get(route('channels.propose', $id))->assertOk();
        $this->travel(7)->hours();
        Http::swap(new Factory);
        Http::fake(fn () => Http::response('', 500));
        $this->get(route('channels.propose', $id))->assertOk()->assertSee('（更新できませんでした）');
    }

    public function test_budget_exhausted_without_cache_shows_limit_message(): void
    {
        app(QuotaLedger::class)->record(QuotaLedger::READ, 2000);
        $id = $this->id();
        $this->get(route('channels.propose', $id))->assertStatus(503)
            ->assertSee('本日の取得上限に達しました')
            ->assertDontSee('保存期限で削除されました');

        DB::table('channel_cache_expirations')->insert(['channel_id' => $id, 'expired_at' => now()->subDay()]);
        $this->get(route('channels.propose', $id))->assertStatus(503)
            ->assertSee('本日の取得上限に達しました')
            ->assertSee('前回の一覧は 30 日の保存期限で削除されました');
    }

    public function test_api_failure_without_cache_is_503(): void
    {
        Http::swap(new Factory);
        Http::fake(fn () => Http::response('', 500));
        $this->get(route('channels.propose', $this->id()))->assertStatus(503)
            ->assertSee('YouTube との通信に失敗しました。時間をおいて再度お試しください');
    }

    public function test_unknown_channel_is_404(): void
    {
        $id = $this->id();
        $this->fakeChannel($id, ['missing' => true]);
        $this->get(route('channels.propose', $id))->assertNotFound()->assertSee('チャンネルが見つかりません');
    }

    public function test_bad_channel_id_is_404_without_api(): void
    {
        Http::fake();
        $this->get('/channels/UCshort/propose')->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_truncated_notice(): void
    {
        $id = $this->setUpChannel();
        $this->get(route('channels.propose', $id))->assertOk()->assertDontSee('これより古い動画');
        DB::table('channel_caches')->where('channel_id', $id)->update(['truncated' => true]);
        $this->get(route('channels.propose', $id))->assertOk()
            ->assertSee('これより古い動画は動画 URL で追加してください');
    }

    public function test_unavailable_ids_come_from_session(): void
    {
        $id = $this->setUpChannel();
        $res = $this->withSession(['unavailable_video_ids' => ['abcdefghijk']])->get(route('channels.propose', $id));
        $this->assertSame(['abcdefghijk'], $this->catalogPayload($res)['unavailableIds']);
    }

    public function test_zero_remaining_shows_reset_time(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-14 03:00:00'));
        $id = $this->setUpChannel();
        app(QuotaLedger::class)->record(QuotaLedger::WRITE, 5000);
        $this->get(route('channels.propose', $id))->assertOk()
            ->assertSee('本日はこれ以上作成できません。次にリセットされるのは 日本時間 11月14日 17時 です');
    }

    public function test_fetch_rate_limit_only_when_api_needed(): void
    {
        $id = $this->setUpChannel();
        for ($i = 0; $i < 10; $i++) {
            $this->get(route('channels.propose', $id))->assertOk();
        }
        // the first import used 1 of 5 per-minute fetches; 4 more pass, the next is 429
        for ($n = 0; $n < 4; $n++) {
            $other = 'UC'.str_repeat((string) $n, 22);
            $this->fakeChannel($other, ['missing' => true]);
            $this->get(route('channels.propose', $other))->assertNotFound();
        }
        $this->get(route('channels.propose', $this->id('z')))->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_form_has_limits_honeypot_and_consent(): void
    {
        $id = $this->setUpChannel();
        $res = $this->get(route('channels.propose', $id))->assertOk();
        foreach (['x-data="proposer"', 'name="title"', 'name="description"', 'name="comment"', 'name="proposer_name"',
            'name="website"', 'name="video_ids[]"', 'action="'.route('proposals.store', $id).'"',
            ':disabled="isSubmitDisabled"', 'x-show="titleOver"', 'x-show="descriptionOver"', 'x-show="commentOver"',
            'x-show="proposerNameOver"', 'x-show="anyOver"', ':class="titleCounterClass"', 'x-show="maxItemsMissing"'] as $s) {
            $res->assertSee($s, false);
        }
        // maxlength counts UTF-16 units (an emoji is 2): the code-point counters and the server rule decide instead
        $this->assertSame(0, preg_match('/<(input|textarea)[^>]*name="(title|description|comment|proposer_name)"[^>]*maxlength/', $res->getContent()));
        foreach (['再生リスト名（必須）', '再生リストの説明（任意）', 'チャンネル主へのコメント（任意）', 'ニックネーム（任意）',
            '再生リスト名 は 150 文字以内で入力してください', '再生リストの説明 は 1000 文字以内で入力してください',
            'チャンネル主へのコメント は 500 文字以内で入力してください', 'ニックネーム は 30 文字以内で入力してください',
            '文字数の上限を超えている項目があるため保存できません'] as $s) {
            $res->assertSee($s);
        }
        $res->assertDontSee('提案者の名前');
        $res->assertSee('同意したものとみなされます');
        $res->assertSee('この画面の操作には JavaScript が必要です');
        $this->assertNoInlineCode($res->getContent());
    }

    public function test_unavailable_ids_are_passed_to_js(): void
    {
        $id = $this->setUpChannel();
        $res = $this->withSession(['unavailable_video_ids' => ['dQw4w9WgXcQ']])->get(route('channels.propose', $id));
        $this->assertSame(['dQw4w9WgXcQ'], $this->catalogPayload($res)['unavailableIds']);
    }

    public function test_demo_seeder_skips_in_production(): void
    {
        $this->app['env'] = 'production';
        (new DemoChannelSeeder)->run();
        $this->assertSame(0, DB::table('channel_caches')->count());

        $this->app['env'] = 'testing';
        (new DemoChannelSeeder)->run();
        $this->assertSame(1, DB::table('channel_caches')->count());
    }

    public function test_placeholder_notice_is_in_markup(): void
    {
        $id = $this->setUpChannel();
        $this->get(route('channels.propose', $id))->assertOk()
            ->assertSee('x-show="hasPlaceholders"', false)
            ->assertSee('一部の動画はタイトルを表示できませんが、選択は保たれています（保存時に YouTube から取得し直します）');
    }
}

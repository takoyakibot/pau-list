<?php

namespace Tests\Feature;

use App\Http\Middleware\RequireDocumentNavigation;
use App\Support\ClientKey;
use App\Support\OpenGraph;
use App\Support\ProposalKey;
use Carbon\CarbonImmutable;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\Support\AssertsNoInlineCode;
use Tests\Support\CreatesProposals;
use Tests\TestCase;

/** Link preview card (spec D49). */
class OpenGraphTest extends TestCase
{
    use AssertsNoInlineCode, CreatesProposals, RefreshDatabase;

    private const CRAWLERS = [
        'Mozilla/5.0 (compatible; Discordbot/2.0; +https://discordapp.com)',
        'Twitterbot/1.0',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-14 03:00:00'));
    }

    /** @return array<string, list<string>> property/name => contents, in order */
    private function metas(TestResponse $res): array
    {
        $html = $res->getContent();
        $head = substr($html, 0, (int) strpos($html, '</head>'));
        preg_match_all('/<meta (?:property|name)="((?:og|twitter):[^"]+|theme-color)" content="([^"]*)">/', $head, $m, PREG_SET_ORDER);
        $metas = [];
        foreach ($m as [, $name, $content]) {
            $metas[$name][] = html_entity_decode($content, ENT_QUOTES | ENT_HTML5);
        }

        return $metas;
    }

    private function assertCard(TestResponse $res, string $title, string $description, string $url): void
    {
        $image = url('/images/og-card.png');
        $this->assertSame([
            'theme-color' => ['#2563eb'],
            'og:site_name' => ['List Maker'],
            'og:type' => ['website'],
            'og:locale' => ['ja_JP'],
            'og:url' => [$url],
            'og:title' => [$title],
            'og:description' => [$description],
            'og:image' => [$image],
            'og:image:width' => ['1200'],
            'og:image:height' => ['630'],
            'og:image:alt' => [OpenGraph::IMAGE_ALT],
            'twitter:card' => ['summary_large_image'],
            'twitter:title' => [$title],
            'twitter:description' => [$description],
            'twitter:image' => [$image],
            'twitter:image:alt' => [OpenGraph::IMAGE_ALT],
        ], $this->metas($res));
    }

    private function assertDefaultCard(TestResponse $res, string $url): void
    {
        $this->assertCard($res, OpenGraph::DEFAULT_TITLE, OpenGraph::DEFAULT_DESCRIPTION, $url);
    }

    public function test_home_has_default_card(): void
    {
        $res = $this->get('/')->assertOk();
        $this->assertDefaultCard($res, url('/'));
        $this->assertNoInlineCode($res->getContent());
        $this->assertLessThanOrEqual(80, mb_strlen(OpenGraph::DEFAULT_DESCRIPTION));
    }

    public function test_og_url_has_no_query_string(): void
    {
        $res = $this->get('/?utm_source=x&a=<b>')->assertOk();
        $this->assertDefaultCard($res, url('/'));

        $key = $this->createProposal();
        $res = $this->get(route('proposals.show', $key).'?ref=discord')->assertOk();
        $this->assertSame([route('proposals.show', $key)], $this->metas($res)['og:url']);
    }

    public function test_absolute_urls_come_from_app_url_not_the_host_header(): void
    {
        config(['app.url' => 'https://list-maker.example']);
        $key = $this->createProposal();

        $res = $this->withHeaders(['Host' => 'evil.example'])->get('http://evil.example/terms?x=1')->assertOk();
        $metas = $this->metas($res);
        $this->assertSame(['https://list-maker.example/terms'], $metas['og:url']);
        $this->assertSame(['https://list-maker.example/images/og-card.png'], $metas['og:image']);
        $this->assertSame(['https://list-maker.example/images/og-card.png'], $metas['twitter:image']);

        $res = $this->withHeaders(['Host' => 'evil.example'])->get("http://evil.example/p/$key?ref=x")->assertOk();
        $metas = $this->metas($res);
        $this->assertSame(["https://list-maker.example/p/$key"], $metas['og:url']);
        $this->assertSame(['https://list-maker.example/images/og-card.png'], $metas['og:image']);

        $res = $this->withHeaders(['Host' => 'evil.example'])->get('http://evil.example/')->assertOk();
        $this->assertSame(['https://list-maker.example'], $this->metas($res)['og:url']);
        $this->assertStringNotContainsString('evil.example', substr($res->getContent(), 0, (int) strpos($res->getContent(), '</head>')));
    }

    public function test_static_pages_have_default_card(): void
    {
        $this->assertDefaultCard($this->get('/terms')->assertOk(), url('/terms'));
        $this->assertDefaultCard($this->get('/privacy')->assertOk(), url('/privacy'));
    }

    public function test_preview_card_has_title_count_and_channel_only(): void
    {
        $key = $this->createProposal([
            'title' => '夏の歌まとめ',
            'channel_title' => 'チャンネル A',
            'proposer_name' => '提案者タロウ',
            'comment' => 'チャンネル主さんへのひみつのコメント',
        ]);

        $res = $this->get(route('proposals.show', $key))->assertOk();
        $this->assertCard(
            $res,
            '「夏の歌まとめ」の再生リスト案',
            '2 本の動画 ・ チャンネル A への提案 ・ List Maker',
            route('proposals.show', $key),
        );

        $head = substr($res->getContent(), 0, (int) strpos($res->getContent(), '</head>'));
        $this->assertStringNotContainsString('提案者タロウ', $head);
        $this->assertStringNotContainsString('ひみつのコメント', $head);
        $this->assertStringNotContainsString('動画 A', $head);
        $this->assertStringNotContainsString('動画 B', $head);
    }

    public function test_preview_card_values_are_escaped(): void
    {
        $key = $this->createProposal([
            'title' => '<script>"x" & \'y\'</script>',
            'channel_title' => 'A&B <"ch">',
        ]);

        $res = $this->get(route('proposals.show', $key))->assertOk();
        $html = $res->getContent();
        $this->assertStringContainsString(
            '<meta property="og:title" content="「&lt;script&gt;&quot;x&quot; &amp; &#039;y&#039;&lt;/script&gt;」の再生リスト案">',
            $html,
        );
        $this->assertStringContainsString(
            '<meta property="og:description" content="2 本の動画 ・ A&amp;B &lt;&quot;ch&quot;&gt; への提案 ・ List Maker">',
            $html,
        );
        $this->assertStringNotContainsString('<script>"x"', $html);
        $this->assertSame(['「<script>"x" & \'y\'</script>」の再生リスト案'], $this->metas($res)['twitter:title']);
    }

    public function test_unknown_or_expired_key_is_plain_404_with_default_card(): void
    {
        $unknown = ProposalKey::generate();
        $res = $this->get(route('proposals.show', $unknown))->assertNotFound();
        // og:url is the home page: the key is not written back into the 404 (one identical 404 for every key).
        $this->assertDefaultCard($res, url('/'));
        $res->assertDontSee($unknown);

        $expired = $this->createProposal(['title' => '期限切れの提案', 'expires_at' => now()->subMinute()]);
        $res = $this->get(route('proposals.show', $expired))->assertNotFound();
        $this->assertDefaultCard($res, url('/'));
        $res->assertDontSee('期限切れの提案');
    }

    public function test_shared_page_has_default_card(): void
    {
        $key = $this->createProposal(['title' => '共有直後の提案']);

        $res = $this->withSession(['proposal_key_shown' => ProposalKey::hash($key)])
            ->get(route('proposals.shared', $key))->assertOk();
        $this->assertDefaultCard($res, url('/'));
        $this->assertSame([OpenGraph::DEFAULT_TITLE], $this->metas($res)['og:title']);
    }

    public function test_owner_edit_page_has_default_card(): void
    {
        // The channel list is unavailable (no read budget): the page still renders without any API call.
        config(['youtube.quota.read_budget' => 0]);
        $key = $this->createProposal(['title' => 'チャンネル主が開く提案']);

        $res = $this->actAsOwner($this->channelId)->get(route('proposals.edit', $key))->assertOk();
        // og:url is the home page: the raw key is never written into S5.
        $this->assertDefaultCard($res, url('/'));
        $res->assertDontSee($key);
        $head = substr($res->getContent(), 0, (int) strpos($res->getContent(), '</head>'));
        $this->assertStringNotContainsString('チャンネル主が開く提案」', $head);
        $this->assertStringNotContainsString('の再生リスト案', $head);
    }

    public function test_other_owner_pages_have_default_card_without_the_key(): void
    {
        $key = $this->createProposal(['title' => '削除する提案']);
        $res = $this->actAsOwner($this->channelId)->get(route('proposals.delete', $key))->assertOk();
        $this->assertDefaultCard($res, url('/'));
        $res->assertDontSee($key);

        $key = $this->createProposal(['title' => '作成中の提案']);
        $this->makeCreation(2, key: $key);
        $res = $this->actAsOwner($this->channelId)->get(route('proposals.creation', $key))->assertOk();
        $this->assertDefaultCard($res, url('/'));
        $res->assertDontSee($key);
    }

    public function test_owner_preview_still_shows_the_preview_card(): void
    {
        $key = $this->createProposal(['title' => 'ログイン中に見る提案']);

        $res = $this->actAsOwner($this->channelId)->get(route('proposals.show', $key))->assertOk();
        $this->assertSame(['「ログイン中に見る提案」の再生リスト案'], $this->metas($res)['og:title']);
    }

    public function test_preview_route_lets_crawlers_through(): void
    {
        $middleware = Route::getRoutes()->getByName('proposals.show')->gatherMiddleware();
        $this->assertNotContains(RequireDocumentNavigation::class, $middleware);
        foreach ($middleware as $m) {
            $this->assertStringStartsNotWith('throttle', is_string($m) ? $m : '');
        }
    }

    public function test_crawler_get_returns_card_without_side_effects(): void
    {
        $key = $this->createProposal(['title' => 'クローラーの提案']);
        $tables = array_values(array_diff(
            array_map(fn (array $t) => $t['name'], Schema::getTables()),
            ['migrations'],
        ));
        $snapshot = fn () => collect($tables)
            ->mapWithKeys(fn (string $t) => [$t => DB::table($t)->get()->map(fn ($r) => (array) $r)->all()])
            ->all();
        $before = $snapshot();
        $cacheWrites = [];
        Event::listen(KeyWritten::class, function (KeyWritten $e) use (&$cacheWrites) {
            $cacheWrites[] = $e->key;
        });

        foreach (self::CRAWLERS as $ua) {
            // Crawlers send no Fetch Metadata (Sec-Fetch-*) headers and no cookies.
            $res = $this->withHeaders(['User-Agent' => $ua, 'Accept' => '*/*'])
                ->get(route('proposals.show', $key))
                ->assertOk()
                ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
            $this->assertSame(['「クローラーの提案」の再生リスト案'], $this->metas($res)['og:title']);
            $this->assertSame(['summary_large_image'], $this->metas($res)['twitter:card']);
        }

        Http::assertNothingSent();
        // Only the site-wide per-IP limiter of the web group (throttle:global, 120/min) counts the request; no
        // feature limit (lookups, proposals, channel fetches) and no YouTube cache entry is touched.
        $global = md5('global'.ClientKey::for(Request::create('/', server: ['REMOTE_ADDR' => '127.0.0.1'])));
        $this->assertSame([], array_values(array_diff(array_unique($cacheWrites), [$global, $global.':timer'])));
        $this->assertEquals($before, $snapshot());
    }

    public function test_og_image_is_a_1200x630_png_in_public(): void
    {
        $path = public_path(OpenGraph::IMAGE_PATH);
        $this->assertFileExists($path);
        $this->assertLessThan(300 * 1024, filesize($path));
        $info = getimagesize($path);
        $this->assertSame([1200, 630, 'image/png'], [$info[0], $info[1], $info['mime']]);
    }
}

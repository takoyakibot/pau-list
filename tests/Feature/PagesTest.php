<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\AssertsNoInlineCode;
use Tests\TestCase;

class PagesTest extends TestCase
{
    use AssertsNoInlineCode;

    public static function publicPages(): array
    {
        return [['/'], ['/privacy'], ['/terms'], ['/contact'], ['/logged-out']];
    }

    #[DataProvider('publicPages')]
    public function test_page_renders_with_footer_links(string $path): void
    {
        $res = $this->get($path)->assertOk();
        $res->assertSee('href="'.route('privacy').'"', false);
        $res->assertSee('href="'.route('terms').'"', false);
        $res->assertSee('href="'.route('contact.show').'"', false);
        $res->assertSee('href="https://www.youtube.com/t/terms"', false);
        $res->assertSee('href="https://policies.google.com/privacy"', false);
        $res->assertSee('<html lang="ja"', false);
    }

    #[DataProvider('publicPages')]
    public function test_page_has_no_inline_script_or_style(string $path): void
    {
        $this->assertNoInlineCode($this->get($path)->getContent());
    }

    public function test_error_page_has_no_inline_script_or_style(): void
    {
        $html = $this->get('/no-such-page')->assertNotFound()->getContent();
        $this->assertNoInlineCode($html);
    }

    public static function errorCodes(): array
    {
        return [[401], [403], [404], [405], [413], [419], [429], [500], [503]];
    }

    #[DataProvider('errorCodes')]
    public function test_error_views_have_suffixed_title_and_no_inline_code(int $code): void
    {
        $html = view("errors.$code", ['exception' => new HttpException($code)])->render();
        $this->assertMatchesRegularExpression('#<title>[^<]+ - List Maker</title>#', $html);
        $this->assertNoInlineCode($html);
    }

    public function test_fallback_error_views_have_suffixed_title(): void
    {
        foreach (['4xx' => 422, '5xx' => 502] as $view => $code) {
            $html = view("errors.$view", ['exception' => new HttpException($code)])->render();
            $this->assertMatchesRegularExpression('#<title>[^<]+ - List Maker</title>#', $html, $view);
            $this->assertNoInlineCode($html);
        }
    }

    public function test_home_explains_tool_and_scope_without_youtube_in_name(): void
    {
        $this->get('/')
            ->assertSee('List Maker')
            ->assertSee('Google ログインで許可いただく YouTube の権限は、チャンネルの確認・自分の限定公開動画の表示・再生リストの作成と動画の追加にのみ使います。')
            ->assertDontSee('YouTube List Maker');
    }

    public function test_home_has_final_name_api_services_note_and_login_flow(): void
    {
        $html = $this->get('/')->assertOk()
            ->assertSee('<title>List Maker</title>', false)
            ->assertDontSee('（仮称）')
            ->assertSee('<h1 class="sr-only">List Maker</h1>', false)
            ->assertSee('本サービスは YouTube API Services を使用しています')
            ->assertSee('ログインの流れ（確認されていないアプリの警告について）')
            ->assertSee('『このアプリは Google で確認されていません』と表示されたら、『詳細』を押し、『（アプリ名またはドメイン）（安全ではないページ）に移動』のようなリンクを選びます。Google の審査が完了するまでは、この警告が表示されます')
            ->assertSee('権限の確認画面にチェックボックスが表示された場合は、YouTube の権限にチェックを入れてください（入れないと再生リストを作成できません）')
            ->assertSee('Google の画面には YouTube アカウントの管理という広い権限が表示されますが、使うのは')
            ->assertDontSee('『List Maker（安全ではないページ）に移動』を選びます')
            ->getContent();
        $this->assertSame(1, preg_match('#<main[^>]*>(.*)</main>#s', $html, $m));
        $main = $m[1];
        foreach ([route('privacy'), route('terms'), 'https://www.youtube.com/t/terms', 'https://policies.google.com/privacy'] as $href) {
            $this->assertStringContainsString('href="'.$href, $main, $href);
        }
        $this->assertMatchesRegularExpression('#<a[^>]*href="https://www.youtube.com/t/terms"[^>]*rel="noopener noreferrer" target="_blank"#', $main);
        $this->assertMatchesRegularExpression('#<section aria-labelledby="home-owner-heading"[^>]*>\s*<h2 id="home-owner-heading"[^>]*>チャンネル主の方へ</h2>.*ログインの流れ#s', $main);
    }

    public function test_home_shows_how_it_works_carousel_above_the_start_form(): void
    {
        $html = $this->get('/')->assertOk()->getContent();
        $this->assertNoInlineCode($html);
        $this->assertSame(1, preg_match('#<main[^>]*>(.*)</main>#s', $html, $m));
        $main = $m[1];

        $this->assertMatchesRegularExpression('#<section aria-roledescription="carousel" aria-label="List Maker のしくみ" x-data="howtoCarousel"#', $main);
        $this->assertMatchesRegularExpression('#<div x-ref="track" tabindex="0"#', $main);
        $this->assertSame(4, substr_count($main, 'role="group" aria-roledescription="slide"'));
        foreach (range(1, 4) as $n) {
            $this->assertStringContainsString('aria-roledescription="slide" aria-label="'.$n.' / 4"', $main);
            $this->assertMatchesRegularExpression('#<button type="button" @click="goTo" data-index="'.($n - 1).'" aria-label="スライド '.$n.'"#', $main);
        }
        $this->assertSame(1, substr_count($main, 'aria-current="true"'));
        $this->assertMatchesRegularExpression('#<button type="button" @click="prev" aria-label="前へ"#', $main);
        $this->assertMatchesRegularExpression('#<button type="button" @click="next" aria-label="次へ"#', $main);
        foreach (['ファンが選んで、チャンネル主が作る。', '動画を選ぶ', 'URL を送る', 'チャンネル主が作る'] as $heading) {
            $this->assertMatchesRegularExpression('#<h2 [^>]*>'.preg_quote($heading, '#').'</h2>#', $main, $heading);
        }

        // The pictures inside the slides are decorative: nothing focusable or form-like inside the track.
        $this->assertSame(1, preg_match('#<div x-ref="track".*?(?=<div class="min-h-11">)#s', $main, $track));
        $this->assertDoesNotMatchRegularExpression('#<(button|input|a|select|textarea)\b#', $track[0]);

        // The start form still follows the carousel.
        $carouselAt = strpos($main, 'aria-roledescription="carousel"');
        $formAt = strpos($main, 'action="'.route('channels.open').'"');
        $this->assertNotFalse($formAt);
        $this->assertGreaterThan($carouselAt, $formAt);
        $this->assertStringContainsString('<input id="q" name="q"', $main);
        // Matches the owner login button's label on the shared page (S4).
        $this->assertStringContainsString('「Google でログインして作成する」からログインします', $main);
    }

    public function test_home_slides_use_the_configured_cap_and_retention(): void
    {
        config(['youtube.quota.write_limit' => 2550, 'listmaker.retention_days' => 14]);

        $this->get('/')->assertOk()
            ->assertSee('最大 50 本まで')
            ->assertSee('/ 50 本')
            ->assertSee('作成から 14 日で自動削除')
            ->assertDontSee('最大 99 本まで');
    }

    public function test_only_spec_routes_are_registered(): void
    {
        // スケルトンの /up（外部 CDN を読む）と local ディスクの storage/{path} 配信は使わない
        $uris = collect(app('router')->getRoutes()->getRoutes())->map->uri()->sort()->values()->all();

        $this->assertSame(['/', 'auth/google', 'auth/google/callback', 'channels', 'channels/{channelId}/proposals', 'channels/{channelId}/propose', 'channels/{channelId}/videos/lookup', 'contact', 'contact', 'deleted', 'logged-out', 'logout', 'owner/videos/unlisted', 'p/{key}', 'p/{key}/creation', 'p/{key}/creation', 'p/{key}/creation/abort', 'p/{key}/creation/pause', 'p/{key}/creation/recreate', 'p/{key}/creation/resume', 'p/{key}/creation/retry', 'p/{key}/creation/step', 'p/{key}/delete', 'p/{key}/deletion', 'p/{key}/edit', 'p/{key}/shared', 'p/{key}/videos/lookup', 'privacy', 'terms'], $uris);
        $this->get('/up')->assertNotFound();
        $this->get('/storage/anything.txt')->assertNotFound();
    }
}

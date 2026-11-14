<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AssertsNoInlineCode;
use Tests\TestCase;

class PagesTest extends TestCase
{
    use AssertsNoInlineCode;

    public static function publicPages(): array
    {
        return [['/'], ['/privacy'], ['/terms'], ['/contact']];
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

    public function test_home_explains_tool_and_scope_without_youtube_in_name(): void
    {
        $this->get('/')
            ->assertSee('List Maker')
            ->assertSee('再生リストの作成と動画の追加')
            ->assertDontSee('YouTube List Maker');
    }

    public function test_only_spec_routes_are_registered(): void
    {
        // スケルトンの /up（外部 CDN を読む）と local ディスクの storage/{path} 配信は使わない
        $uris = collect(app('router')->getRoutes()->getRoutes())->map->uri()->sort()->values()->all();

        $this->assertSame(['/', 'auth/google', 'auth/google/callback', 'channels', 'channels/{channelId}/proposals', 'channels/{channelId}/propose', 'channels/{channelId}/videos/lookup', 'contact', 'owner/videos/unlisted', 'p/{key}', 'p/{key}/edit', 'p/{key}/shared', 'privacy', 'terms'], $uris);
        $this->get('/up')->assertNotFound();
        $this->get('/storage/anything.txt')->assertNotFound();
    }
}

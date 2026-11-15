<?php

namespace App\Support;

use App\Models\Proposal;

/**
 * Link preview card (OGP + Twitter Card) in layouts.app (spec D49). Every page gets the generic card; only S4
 * (the public preview) passes its own title and description. Values are escaped by Blade in partials.og.
 */
final class OpenGraph
{
    public const SITE_NAME = 'List Maker';

    public const DEFAULT_TITLE = 'List Maker — 再生リストの提案ツール';

    public const DEFAULT_DESCRIPTION = 'チャンネルの動画を選んで、再生リストの案を URL で届けよう。チャンネル主はログインしてそのまま作成できます。';

    public const IMAGE_PATH = 'images/og-card.png';

    public const IMAGE_WIDTH = 1200;

    public const IMAGE_HEIGHT = 630;

    public const IMAGE_ALT = 'List Maker — ファンが選んで、チャンネル主が作る。再生リストの提案ツール';

    public const THEME_COLOR = '#2563eb';

    /** Display widths (mb_strimwidth) of the proposal title and the channel title in S4's card. */
    public const TITLE_WIDTH = 120;

    public const CHANNEL_WIDTH = 100;

    /**
     * S4 only. The proposer name, the comment (meant for the owner) and the video titles are not included.
     * $key is the raw key of the preview's own URL (the shared URL; S4 is the one page meant to be passed around).
     *
     * @return array{title: string, description: string, url: string}
     */
    public static function forProposal(Proposal $proposal, int $itemCount, string $key): array
    {
        return [
            'url' => self::absolute(route('proposals.show', $key, false)),
            'title' => '「'.self::clip($proposal->title, self::TITLE_WIDTH).'」の再生リスト案',
            'description' => $itemCount.' 本の動画 ・ '.self::clip($proposal->channel_title, self::CHANNEL_WIDTH)
                .' への提案 ・ '.self::SITE_NAME,
        ];
    }

    /** One line (control characters, newlines, separators and whitespace runs become one space; ZWJ etc. are kept), cut to $width with "…". */
    private static function clip(string $text, int $width): string
    {
        $line = trim((string) preg_replace('/[\p{Cc}\p{Z}\s]+/u', ' ', $text));

        return mb_strimwidth($line, 0, $width, '…', 'UTF-8');
    }

    /**
     * @param  array{title?: string, description?: string, url?: string}|null  $overrides
     * @return array{title: string, description: string, url: string, image: string}
     */
    public static function resolve(?array $overrides): array
    {
        return [
            'title' => $overrides['title'] ?? self::DEFAULT_TITLE,
            'description' => $overrides['description'] ?? self::DEFAULT_DESCRIPTION,
            'url' => $overrides['url'] ?? self::defaultUrl(),
            'image' => self::absolute('/'.self::IMAGE_PATH),
        ];
    }

    /**
     * The page itself without the query string. Pages under /p/{key} other than S4
     * (shared, S5, S5a, S6 and their errors) and pages without a route (unmatched 404s) point to the home page
     * instead: the raw key is never written into those pages, and unknown/expired keys keep one identical 404.
     */
    private static function defaultUrl(): string
    {
        $route = request()->route();

        return self::absolute($route === null || in_array('key', $route->parameterNames(), true)
            ? '/'
            : request()->getPathInfo());
    }

    /**
     * Absolute URLs come from APP_URL, never from the request's Host header: a spoofed Host must not end up in a
     * crawler's cached card.
     */
    private static function absolute(string $path): string
    {
        $base = rtrim((string) config('app.url'), '/');

        return $path === '/' || $path === '' ? $base : $base.'/'.ltrim($path, '/');
    }
}

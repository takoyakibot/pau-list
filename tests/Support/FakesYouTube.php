<?php

namespace Tests\Support;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

trait FakesYouTube
{
    private const YT = 'https://www.googleapis.com/youtube/v3/';

    protected function fakeChannel(string $id, array $o = []): void
    {
        $uploads = $o['uploads'] ?? 'UU'.substr($id, 2);
        $items = ($o['missing'] ?? false) ? [] : [[
            'id' => $id,
            'snippet' => [
                'title' => $o['title'] ?? 'Channel '.$id,
                'customUrl' => $o['handle'] ?? '@channel',
                'thumbnails' => ['default' => ['url' => $o['thumbnail'] ?? 'https://yt3.ggpht.com/'.$id]],
            ],
            'contentDetails' => ['relatedPlaylists' => ['uploads' => $uploads]],
        ]];
        $this->fakeEndpoint('channels', fn () => Http::response(['items' => $items]));
    }

    /** @param list<list<array>> $pages */
    protected function fakeUploads(string $playlistId, array $pages): void
    {
        $this->fakeEndpoint('playlistItems', function (Request $request) use ($playlistId, $pages) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);
            if (($q['playlistId'] ?? null) !== $playlistId) {
                return null;
            }
            if ($pages === []) {
                return $this->errorResponse(404, 'playlistNotFound');
            }
            $token = $q['pageToken'] ?? null;
            $index = $token === null ? 0 : ((int) substr($token, 1)) - 1;
            $body = ['items' => $pages[$index] ?? []];
            if (isset($pages[$index + 1])) {
                $body['nextPageToken'] = 'p'.($index + 2);
            }

            return Http::response($body);
        });
    }

    /** @param list<array> $videos */
    protected function fakeVideos(array $videos): void
    {
        $this->fakeEndpoint('videos', function (Request $request) use ($videos) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);
            $ids = explode(',', $q['id'] ?? '');

            return Http::response(['items' => array_values(array_filter($videos, fn ($v) => in_array($v['id'], $ids, true)))]);
        });
    }

    protected function uploadItem(string $id, string $publishedAt, string $privacy = 'public', ?string $title = null): array
    {
        return [
            'snippet' => [
                'title' => $title ?? 'Video '.$id,
                'resourceId' => ['videoId' => $id],
                'thumbnails' => ['medium' => ['url' => 'https://i.ytimg.com/vi/'.$id.'/mqdefault.jpg']],
            ],
            'contentDetails' => ['videoId' => $id, 'videoPublishedAt' => $publishedAt],
            'status' => ['privacyStatus' => $privacy],
        ];
    }

    protected function videoResource(string $id, string $channelId, array $o = []): array
    {
        return [
            'id' => $id,
            'snippet' => [
                'title' => $o['title'] ?? 'Video '.$id,
                'channelId' => $channelId,
                'channelTitle' => $o['channelTitle'] ?? 'Channel',
                'publishedAt' => $o['publishedAt'] ?? '2025-01-02T03:04:05Z',
                'thumbnails' => ['medium' => ['url' => $o['thumbnail'] ?? 'https://i.ytimg.com/vi/'.$id.'/mqdefault.jpg']],
            ],
            'contentDetails' => ['duration' => $o['duration'] ?? 'PT3M'],
            'status' => ['privacyStatus' => $o['privacy'] ?? 'public'],
        ];
    }

    protected function fakeYouTubeError(string $endpoint, int $status, string $reason): void
    {
        $this->fakeEndpoint($endpoint, fn () => $this->errorResponse($status, $reason));
    }

    private function errorResponse(int $status, string $reason)
    {
        return Http::response(['error' => [
            'code' => $status,
            'message' => 'fake',
            'errors' => [['reason' => $reason]],
        ]], $status);
    }

    private function fakeEndpoint(string $endpoint, callable $handler): void
    {
        Http::fake(function (Request $request) use ($endpoint, $handler) {
            if (! str_starts_with($request->url(), self::YT.$endpoint.'?')) {
                return null;
            }

            return $handler($request);
        });
    }
}

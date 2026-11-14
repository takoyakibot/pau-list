<?php

namespace Tests\Support;

use App\Services\YouTube\GoogleOAuth;
use App\Services\YouTube\YouTubeReader;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

trait FakesGoogle
{
    /** A token endpoint response. Keys overridden with null are removed from the body. */
    protected function fakeToken(array $override = [], int $status = 200): void
    {
        $body = array_filter(array_merge([
            'access_token' => 'ya29.test-access-token',
            'expires_in' => 3599,
            'token_type' => 'Bearer',
            'scope' => GoogleOAuth::SCOPE,
        ], $override), fn ($v) => $v !== null);

        $this->fakeGoogleUrl(GoogleOAuth::TOKEN_URL, fn () => Http::response($body, $status));
    }

    protected function fakeRevoke(int $status = 200): void
    {
        $this->fakeGoogleUrl(GoogleOAuth::REVOKE_URL, fn () => Http::response('', $status));
    }

    /** channels.list?mine=true for the token's account; null = an account without a YouTube channel. */
    protected function fakeMyChannel(?string $channelId, string $title = 'My Channel'): void
    {
        $this->fakeGoogleUrl(YouTubeReader::BASE_URL.'channels?', function (Request $request) use ($channelId, $title) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);
            if (($q['mine'] ?? null) !== 'true') {
                return null;
            }
            $items = $channelId === null ? [] : [['kind' => 'youtube#channel', 'id' => $channelId, 'snippet' => ['title' => $title]]];

            return Http::response(['kind' => 'youtube#channelListResponse', 'items' => $items]);
        });
    }

    /** POST playlists (playlists.insert): the new playlist id, or an error with $status/$reason. */
    protected function fakePlaylistInsert(string $id = 'PLnew', int $status = 200, ?string $reason = null): void
    {
        $this->fakeGoogleRequest('POST', YouTubeReader::BASE_URL.'playlists?', fn () => $status === 200
            ? Http::response(['kind' => 'youtube#playlist', 'id' => $id])
            : $this->googleError($status, $reason));
    }

    /**
     * POST playlistItems (playlistItems.insert). An array answers the requests in order, one element each:
     * a string = success with that item id, an int = error status, [int $status, ?string $reason] = error,
     * or a ready response. A closure receives the Request and returns the response (it may also throw).
     *
     * @param  callable(Request): mixed|list<mixed>  $responder
     */
    protected function fakePlaylistItemInsert(callable|array $responder): void
    {
        if (is_array($responder)) {
            $queue = array_values($responder);
            $responder = function () use (&$queue) {
                if ($queue === []) {
                    throw new \LogicException('No more fake playlistItems.insert responses.');
                }
                $next = array_shift($queue);

                return match (true) {
                    is_string($next) => Http::response(['kind' => 'youtube#playlistItem', 'id' => $next]),
                    is_int($next) => $this->googleError($next, null),
                    is_array($next) => $this->googleError($next[0], $next[1] ?? null),
                    default => $next,
                };
            };
        }
        $this->fakeGoogleRequest('POST', YouTubeReader::BASE_URL.'playlistItems?', $responder);
    }

    /**
     * GET playlists?mine=true (one page). Each item: ['id' => …, 'title' => …, 'publishedAt' => ISO-8601|null, 'description' => …].
     *
     * @param  list<array{id: string, title?: string, publishedAt?: string|null, description?: string}>  $items
     */
    protected function fakeMyPlaylists(array $items, bool $hasMore = false): void
    {
        $this->fakeGoogleRequest('GET', YouTubeReader::BASE_URL.'playlists?', function (Request $request) use ($items, $hasMore) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);
            if (($q['mine'] ?? null) !== 'true') {
                return null;
            }
            $body = ['kind' => 'youtube#playlistListResponse', 'items' => array_map(fn (array $i) => [
                'kind' => 'youtube#playlist',
                'id' => $i['id'],
                'snippet' => array_filter([
                    'title' => $i['title'] ?? 'Playlist '.$i['id'],
                    'publishedAt' => array_key_exists('publishedAt', $i) ? $i['publishedAt'] : '2026-11-15T00:00:00Z',
                    'description' => $i['description'] ?? null,
                ], fn ($v) => $v !== null),
            ], $items)];
            if ($hasMore) {
                $body['nextPageToken'] = 'more';
            }

            return Http::response($body);
        });
    }

    /**
     * GET playlistItems?playlistId=… of an owner playlist, 50 per page in the given order.
     *
     * @param  array<string, string>  $videoIdToItemId
     */
    protected function fakePlaylistItemsList(array $videoIdToItemId): void
    {
        $this->fakeGoogleRequest('GET', YouTubeReader::BASE_URL.'playlistItems?', function (Request $request) use ($videoIdToItemId) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);
            $page = (int) ($q['pageToken'] ?? 0);
            $pairs = array_slice($videoIdToItemId, $page * 50, 50, true);
            $items = [];
            foreach ($pairs as $videoId => $itemId) {
                $items[] = ['kind' => 'youtube#playlistItem', 'id' => $itemId, 'snippet' => [
                    'playlistId' => $q['playlistId'] ?? '',
                    'resourceId' => ['kind' => 'youtube#video', 'videoId' => (string) $videoId],
                ]];
            }
            $body = ['kind' => 'youtube#playlistItemListResponse', 'items' => $items];
            if (count($videoIdToItemId) > ($page + 1) * 50) {
                $body['nextPageToken'] = (string) ($page + 1);
            }

            return Http::response($body);
        });
    }

    /**
     * GET videos with the owner's token: answers with the given resources (FakesYouTube::videoResource) whose id was asked for.
     *
     * @param  list<array<string, mixed>>  $videoResources
     */
    protected function fakeOwnerVideos(array $videoResources): void
    {
        $this->fakeGoogleRequest('GET', YouTubeReader::BASE_URL.'videos?', function (Request $request) use ($videoResources) {
            if (! $request->hasHeader('Authorization')) {
                return null;
            }
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);
            $ids = explode(',', (string) ($q['id'] ?? ''));

            return Http::response(['kind' => 'youtube#videoListResponse', 'items' => array_values(array_filter(
                $videoResources,
                fn (array $v) => in_array($v['id'] ?? null, $ids, true),
            ))]);
        });
    }

    protected function googleError(int $status, ?string $reason): PromiseInterface
    {
        return Http::response(['error' => ['code' => $status, 'errors' => [['reason' => $reason]]]], $status);
    }

    /** @return Collection<int, Request> */
    protected function googleRequests(string $urlPrefix): Collection
    {
        return Http::recorded(fn (Request $r) => str_starts_with($r->url(), $urlPrefix))
            ->map(fn (array $pair) => $pair[0])
            ->values();
    }

    private function fakeGoogleRequest(string $method, string $prefix, callable $handler): void
    {
        Http::fake(function (Request $request) use ($method, $prefix, $handler) {
            return $request->method() === $method && str_starts_with($request->url(), $prefix) ? $handler($request) : null;
        });
    }

    private function fakeGoogleUrl(string $prefix, callable $handler): void
    {
        Http::fake(function (Request $request) use ($prefix, $handler) {
            return str_starts_with($request->url(), $prefix) ? $handler($request) : null;
        });
    }
}

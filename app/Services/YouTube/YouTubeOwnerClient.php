<?php

namespace App\Services\YouTube;

use App\Support\ChannelInput;
use App\Support\VideoUrl;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * YouTube calls made with the channel owner's OAuth access token (never the API key).
 * Phase 2 reads the owner's own channel and the owner's unlisted uploads (user decision #9);
 * usage is recorded in the owner_read bucket without a cap. Nothing read here is stored.
 * Phase 3 adds the writes (playlists.insert / playlistItems.insert), which are NOT recorded here:
 * WriteLock::consume() counts them right before the call (spec 5.4). Lookups to reconcile an uncertain
 * write and the final videos check are owner_read.
 */
class YouTubeOwnerClient
{
    private const PAGE_SIZE = 50;

    private const PLAYLIST_ID_PATTERN = '/^[A-Za-z0-9_-]{2,64}$/D';

    private const PLAYLIST_ITEM_ID_PATTERN = '/^[A-Za-z0-9_=-]{1,128}$/D';

    private const DEADLINE_SECONDS = 30;

    public function __construct(private readonly QuotaLedger $ledger) {}

    /**
     * The channel of the account the token belongs to; null when it has none (or the id is malformed).
     *
     * @throws YouTubeApiException
     */
    public function myChannel(#[\SensitiveParameter] string $accessToken): ?OwnerChannel
    {
        $item = $this->firstItem($this->get($accessToken, 'channels', ['part' => 'snippet', 'mine' => 'true']), 'channels');
        $id = $item['id'] ?? null;
        if (! is_string($id) || preg_match(ChannelInput::ID_PATTERN, $id) !== 1) {
            return null;
        }
        $title = $item['snippet']['title'] ?? null;

        return new OwnerChannel($id, is_string($title) ? $title : '');
    }

    /**
     * The owner's unlisted uploads (private ones excluded), newest first as YouTube returns them.
     * The uploads playlist id is derived from the channel id (UC… → UU…), so no channels.list call.
     * Paging stops at the same cap as the channel list (fetch_max_videos / 50 pages, 1 unit each), or as soon
     * as more than $max unlisted uploads are found (so the caller can tell that more exist; up to $max + 1 are
     * returned). Empty for a malformed channel id or a channel without uploads. Never stored in shared caches.
     *
     * @return list<PlaylistItem>
     *
     * @throws YouTubeApiException
     */
    public function unlistedUploads(#[\SensitiveParameter] string $accessToken, string $channelId, int $max = PHP_INT_MAX): array
    {
        if (preg_match(ChannelInput::ID_PATTERN, $channelId) !== 1) {
            return [];
        }
        $uploads = 'UU'.substr($channelId, 2);

        $maxPages = max(1, intdiv((int) config('youtube.channel.fetch_max_videos'), self::PAGE_SIZE));
        $deadline = CarbonImmutable::now()->addSeconds(self::DEADLINE_SECONDS);
        $unlisted = [];
        $token = null;
        for ($pages = 0; $pages < $maxPages; $pages++) {
            if (CarbonImmutable::now()->greaterThan($deadline)) {
                throw $this->fail('playlistItems', YouTubeError::connection());
            }
            $query = ['part' => 'snippet,contentDetails,status', 'maxResults' => self::PAGE_SIZE, 'playlistId' => $uploads];
            if ($token !== null) {
                $query['pageToken'] = $token;
            }
            try {
                $json = $this->get($accessToken, 'playlistItems', $query, $token === null);
            } catch (YouTubeApiException $e) {
                if ($token === null && $e->error->status === 404 && $e->error->reason === 'playlistNotFound') {
                    return []; // a channel without uploads has no uploads playlist yet
                }
                throw $e;
            }
            $raw = $json['items'] ?? [];
            if (! is_array($raw)) {
                throw $this->fail('playlistItems', YouTubeError::malformed());
            }
            $page = YouTubeReader::playlistItemsPage(array_values(array_filter($raw, 'is_array')), $json['nextPageToken'] ?? null);
            foreach ($page->items as $playlistItem) {
                if ($playlistItem->privacyStatus === 'unlisted') {
                    $unlisted[$playlistItem->videoId] ??= $playlistItem;
                }
            }
            $token = $page->nextPageToken;
            if ($token === null || count($unlisted) > $max) {
                break;
            }
        }

        return array_slice(array_values($unlisted), 0, $max === PHP_INT_MAX ? null : $max + 1);
    }

    public const PRIVACY_STATUSES = ['private', 'unlisted', 'public'];

    /**
     * playlists.insert. Returns the new playlist id. Quota is counted by the caller (WriteLock::consume()).
     * The input is checked before any request (privacy one of PRIVACY_STATUSES, title 1–150 characters,
     * description up to 1,000): a value YouTube would reject never reaches it.
     *
     * @throws \InvalidArgumentException
     * @throws YouTubeApiException
     */
    public function insertPlaylist(#[\SensitiveParameter] string $accessToken, string $title, string $description, string $privacy): string
    {
        if (! in_array($privacy, self::PRIVACY_STATUSES, true)) {
            throw new \InvalidArgumentException('Invalid playlist privacy status.');
        }
        $titleLength = mb_strlen($title, 'UTF-8');
        if ($titleLength < 1 || $titleLength > 150 || mb_strlen($description, 'UTF-8') > 1000) {
            throw new \InvalidArgumentException('Playlist title must be 1-150 characters and the description at most 1000.');
        }

        $json = $this->post($accessToken, 'playlists', ['part' => 'snippet,status'], [
            'snippet' => ['title' => $title, 'description' => $description],
            'status' => ['privacyStatus' => $privacy],
        ]);

        return $this->idOrFail($json, self::PLAYLIST_ID_PATTERN, 'playlists');
    }

    /**
     * playlistItems.insert at an explicit position. Returns the new playlist item id. Quota is counted by the caller.
     *
     * @throws YouTubeApiException
     */
    public function insertPlaylistItem(#[\SensitiveParameter] string $accessToken, string $playlistId, string $videoId, int $position): string
    {
        $json = $this->post($accessToken, 'playlistItems', ['part' => 'snippet'], [
            'snippet' => [
                'playlistId' => $playlistId,
                'position' => $position,
                'resourceId' => ['kind' => 'youtube#video', 'videoId' => $videoId],
            ],
        ]);

        return $this->idOrFail($json, self::PLAYLIST_ITEM_ID_PATTERN, 'playlistItems');
    }

    /**
     * The first page (50, newest first) of the owner's playlists: owner_read 1. Malformed ids are skipped.
     *
     * @throws YouTubeApiException
     */
    public function myPlaylists(#[\SensitiveParameter] string $accessToken): OwnerPlaylistsPage
    {
        $json = $this->get($accessToken, 'playlists', ['part' => 'snippet', 'mine' => 'true', 'maxResults' => self::PAGE_SIZE]);
        $items = [];
        foreach ($this->items($json, 'playlists') as $item) {
            $id = $item['id'] ?? null;
            if (! is_string($id) || preg_match(self::PLAYLIST_ID_PATTERN, $id) !== 1) {
                continue;
            }
            $items[] = new OwnerPlaylist(
                $id,
                YouTubeReader::title($item['snippet']['title'] ?? null),
                YouTubeReader::date($item['snippet']['publishedAt'] ?? null),
                is_string($d = $item['snippet']['description'] ?? null) ? $d : '',
            );
        }
        $next = $json['nextPageToken'] ?? null;

        return new OwnerPlaylistsPage($items, is_string($next) && $next !== '');
    }

    /**
     * videoId => playlistItemId of a playlist, reading at most $maxPages pages (owner_read 1 each).
     * A video that appears more than once keeps its first item. $truncated is set to true when more pages
     * remained after $maxPages (the result is then not the whole playlist).
     *
     * @return array<string, string>
     *
     * @throws YouTubeApiException
     */
    public function playlistVideoItems(#[\SensitiveParameter] string $accessToken, string $playlistId, int $maxPages, ?bool &$truncated = null): array
    {
        $truncated = false;
        $found = [];
        $token = null;
        for ($pages = 0; $pages < $maxPages; $pages++) {
            $query = ['part' => 'snippet', 'maxResults' => self::PAGE_SIZE, 'playlistId' => $playlistId];
            if ($token !== null) {
                $query['pageToken'] = $token;
            }
            $json = $this->get($accessToken, 'playlistItems', $query);
            foreach ($this->items($json, 'playlistItems') as $item) {
                $itemId = $item['id'] ?? null;
                $videoId = $item['snippet']['resourceId']['videoId'] ?? null;
                if (! is_string($itemId) || preg_match(self::PLAYLIST_ITEM_ID_PATTERN, $itemId) !== 1
                    || ! is_string($videoId) || preg_match(VideoUrl::ID_PATTERN, $videoId) !== 1) {
                    continue;
                }
                $found[$videoId] ??= $itemId;
            }
            $next = $json['nextPageToken'] ?? null;
            $token = is_string($next) && $next !== '' ? $next : null;
            if ($token === null) {
                break;
            }
        }
        $truncated = $token !== null;

        return $found;
    }

    /**
     * videos.list with the owner's token (so the owner's unlisted/private videos are visible), 50 ids per call,
     * owner_read 1 each. Parsed exactly like YouTubeReader::videos().
     *
     * @param  list<string>  $ids
     * @return array<string, VideoInfo>
     *
     * @throws YouTubeApiException
     */
    public function videos(#[\SensitiveParameter] string $accessToken, array $ids): array
    {
        $result = [];
        $ids = array_values(array_unique(array_filter($ids, fn ($id) => is_string($id) && preg_match(VideoUrl::ID_PATTERN, $id) === 1)));
        foreach (array_chunk($ids, self::PAGE_SIZE) as $chunk) {
            $json = $this->get($accessToken, 'videos', [
                'part' => 'snippet,contentDetails,status',
                'id' => implode(',', $chunk),
                'maxResults' => self::PAGE_SIZE,
            ]);
            foreach ($this->items($json, 'videos') as $item) {
                $video = YouTubeReader::videoInfo($item);
                if ($video !== null && in_array($video->id, $chunk, true)) {
                    $result[$video->id] = $video;
                }
            }
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws YouTubeApiException
     */
    private function get(#[\SensitiveParameter] string $accessToken, string $endpoint, array $query, bool $quietMissingPlaylist = false): array
    {
        $this->ledger->record(QuotaLedger::OWNER_READ, 1);

        try {
            $response = Http::withToken($accessToken)
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(15)
                ->withoutRedirecting()
                ->get(YouTubeReader::BASE_URL.$endpoint, $query);
        } catch (ConnectionException|RequestException) {
            // Exception messages may carry the request or body: classify without them.
            throw $this->fail($endpoint, YouTubeError::connection());
        }

        if (! $response->successful()) {
            $error = YouTubeError::fromResponse($response);
            if ($quietMissingPlaylist && $error->status === 404 && $error->reason === 'playlistNotFound') {
                throw new YouTubeApiException($error);
            }
            throw $this->fail($endpoint, $error);
        }
        $json = $response->json();
        if (! is_array($json)) {
            throw $this->fail($endpoint, YouTubeError::malformed());
        }

        return $json;
    }

    /**
     * A JSON POST with the same timeouts, no redirects and error classification as get(). Not recorded in the
     * ledger: writes are counted by WriteLock::consume() before the call.
     *
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     *
     * @throws YouTubeApiException
     */
    private function post(#[\SensitiveParameter] string $accessToken, string $endpoint, array $query, array $body): array
    {
        try {
            $response = Http::withToken($accessToken)
                ->acceptJson()
                ->asJson()
                ->connectTimeout(5)
                ->timeout(15)
                ->withoutRedirecting()
                ->withQueryParameters($query)
                ->post(YouTubeReader::BASE_URL.$endpoint, $body);
        } catch (ConnectionException|RequestException) {
            // Exception messages may carry the request or body: classify without them.
            throw $this->fail($endpoint, YouTubeError::connection());
        }

        if (! $response->successful()) {
            throw $this->fail($endpoint, YouTubeError::fromResponse($response));
        }
        $json = $response->json();
        if (! is_array($json)) {
            throw $this->fail($endpoint, YouTubeError::malformed());
        }

        return $json;
    }

    /** @param array<string, mixed> $json */
    private function idOrFail(array $json, string $pattern, string $endpoint): string
    {
        $id = $json['id'] ?? null;
        if (! is_string($id) || preg_match($pattern, $id) !== 1) {
            throw $this->fail($endpoint, YouTubeError::malformed());
        }

        return $id;
    }

    /**
     * @param  array<string, mixed>  $json
     * @return list<array<string, mixed>>
     */
    private function items(array $json, string $endpoint): array
    {
        $items = $json['items'] ?? [];
        if (! is_array($items)) {
            throw $this->fail($endpoint, YouTubeError::malformed());
        }

        return array_values(array_filter($items, 'is_array'));
    }

    /**
     * @param  array<string, mixed>  $json
     * @return array<string, mixed>|null
     */
    private function firstItem(array $json, string $endpoint): ?array
    {
        return $this->items($json, $endpoint)[0] ?? null;
    }

    private function fail(string $endpoint, YouTubeError $error): YouTubeApiException
    {
        Log::warning('youtube owner api error', [
            'endpoint' => $endpoint,
            'status' => $error->status,
            'reason' => $error->reason,
            'kind' => $error->kind->value,
        ]);

        return new YouTubeApiException($error);
    }
}

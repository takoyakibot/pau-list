<?php

namespace App\Services\YouTube;

use App\Support\ChannelInput;
use App\Support\ChannelRef;
use App\Support\IsoDuration;
use App\Support\VideoUrl;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class YouTubeReader
{
    public const BASE_URL = 'https://www.googleapis.com/youtube/v3/';

    private const CHANNEL_THUMB_PREFIXES = ['https://yt3.ggpht.com/', 'https://i.ytimg.com/'];

    private const UPLOADS_PATTERN = '/^UU[A-Za-z0-9_-]{22}$/D';

    private const VIDEO_THUMB_PREFIXES = ['https://i.ytimg.com/'];

    /** Every string written to a varchar(255) column must fit; URLs and handles are dropped, not cut. */
    private const MAX_COLUMN = 255;

    public function __construct(private readonly QuotaLedger $ledger) {}

    public function channel(ChannelRef $ref): ?ChannelInfo
    {
        $query = ['part' => 'snippet,contentDetails'];
        if ($ref->isId()) {
            $query['id'] = $ref->value;
        } else {
            $query['forHandle'] = '@'.$ref->value;
        }

        $item = $this->items($this->get('channels', $query, QuotaLedger::READ), 'channels')[0] ?? null;
        if ($item === null) {
            return null;
        }
        $id = $item['id'] ?? null;
        $uploads = $item['contentDetails']['relatedPlaylists']['uploads'] ?? null;
        if (! is_string($id) || preg_match(ChannelInput::ID_PATTERN, $id) !== 1
            || ! is_string($uploads) || preg_match(self::UPLOADS_PATTERN, $uploads) !== 1) {
            return null;
        }
        $custom = $item['snippet']['customUrl'] ?? null;

        return new ChannelInfo(
            $id,
            self::title($item['snippet']['title'] ?? null),
            self::thumbnail($item['snippet']['thumbnails'] ?? null, self::CHANNEL_THUMB_PREFIXES),
            $uploads,
            is_string($custom) && $custom !== '' && mb_strlen($custom) <= self::MAX_COLUMN
                ? mb_strtolower(ltrim($custom, '@'))
                : null,
        );
    }

    public function uploadsPage(string $playlistId, ?string $pageToken): PlaylistItemsPage
    {
        $query = ['part' => 'snippet,contentDetails,status', 'maxResults' => 50, 'playlistId' => $playlistId];
        if ($pageToken !== null) {
            $query['pageToken'] = $pageToken;
        }

        try {
            $json = $this->get('playlistItems', $query, QuotaLedger::READ, true);
        } catch (YouTubeApiException $e) {
            if ($e->error->status === 404 && $e->error->reason === 'playlistNotFound') {
                if ($pageToken === null) {
                    // A channel with no uploads: its uploads playlist does not exist yet.
                    return new PlaylistItemsPage([], null);
                }
                // Vanishing mid-pagination is not "end of list": fail so nothing partial is stored.
                throw $this->fail('playlistItems', $e->error);
            }
            throw $e;
        }

        return self::playlistItemsPage($this->items($json, 'playlistItems'), $json['nextPageToken'] ?? null);
    }

    /**
     * Shared with YouTubeOwnerClient (the owner's own uploads): the same validation for every playlistItems page.
     *
     * @param  list<array<string, mixed>>  $rawItems
     */
    public static function playlistItemsPage(array $rawItems, mixed $nextPageToken): PlaylistItemsPage
    {
        $items = [];
        foreach ($rawItems as $item) {
            $videoId = $item['contentDetails']['videoId'] ?? $item['snippet']['resourceId']['videoId'] ?? null;
            if (! is_string($videoId) || preg_match(VideoUrl::ID_PATTERN, $videoId) !== 1) {
                continue;
            }
            $items[] = new PlaylistItem(
                $videoId,
                self::title($item['snippet']['title'] ?? null),
                self::thumbnail($item['snippet']['thumbnails'] ?? null, self::VIDEO_THUMB_PREFIXES),
                self::date($item['contentDetails']['videoPublishedAt'] ?? null),
                self::str($item['status']['privacyStatus'] ?? null, 'unknown'),
            );
        }

        return new PlaylistItemsPage($items, is_string($nextPageToken) && $nextPageToken !== '' ? $nextPageToken : null);
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, VideoInfo>
     */
    public function videos(array $ids, string $bucket = QuotaLedger::READ): array
    {
        $result = [];
        $ids = array_values(array_unique(array_filter($ids, fn ($id) => is_string($id) && preg_match(VideoUrl::ID_PATTERN, $id) === 1)));
        foreach (array_chunk($ids, 50) as $chunk) {
            $json = $this->get('videos', [
                'part' => 'snippet,contentDetails,status',
                'id' => implode(',', $chunk),
                'maxResults' => 50,
            ], $bucket);

            foreach ($this->items($json, 'videos') as $item) {
                $id = $item['id'] ?? null;
                if (! is_string($id) || ! in_array($id, $chunk, true)) {
                    continue;
                }
                $result[$id] = new VideoInfo(
                    $id,
                    self::title($item['snippet']['title'] ?? null),
                    self::thumbnail($item['snippet']['thumbnails'] ?? null, self::VIDEO_THUMB_PREFIXES),
                    self::channelId($item['snippet']['channelId'] ?? null),
                    self::title($item['snippet']['channelTitle'] ?? null),
                    IsoDuration::toSeconds(is_string($d = $item['contentDetails']['duration'] ?? null) ? $d : null),
                    self::date($item['snippet']['publishedAt'] ?? null),
                    self::str($item['status']['privacyStatus'] ?? null, 'unknown'),
                );
            }
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function get(string $endpoint, array $query, string $bucket, bool $tolerateMissingPlaylist = false): array
    {
        if ($bucket === QuotaLedger::READ) {
            if (! $this->ledger->tryConsumeRead(1)) {
                throw new ReadBudgetExceeded('Daily read budget exhausted.');
            }
        } else {
            $this->ledger->record($bucket, 1);
        }

        try {
            $response = Http::withHeaders(['X-Goog-Api-Key' => (string) config('youtube.api_key')])
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(15)
                ->withoutRedirecting()
                ->get(self::BASE_URL.$endpoint, $query);
        } catch (ConnectionException) {
            throw $this->fail($endpoint, YouTubeError::connection());
        }

        if (! $response->successful()) {
            $error = YouTubeError::fromResponse($response);
            if ($tolerateMissingPlaylist && $error->status === 404 && $error->reason === 'playlistNotFound') {
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

    private function fail(string $endpoint, YouTubeError $error): YouTubeApiException
    {
        Log::warning('youtube api error', [
            'endpoint' => $endpoint,
            'status' => $error->status,
            'reason' => $error->reason,
            'kind' => $error->kind->value,
        ]);

        return new YouTubeApiException($error);
    }

    /** @return list<array<string, mixed>> */
    private function items(array $json, string $endpoint): array
    {
        $items = $json['items'] ?? [];
        if (! is_array($items)) {
            throw $this->fail($endpoint, YouTubeError::malformed());
        }

        return array_values(array_filter($items, 'is_array'));
    }

    private static function str(mixed $value, string $default = ''): string
    {
        return is_string($value) ? $value : $default;
    }

    /** A malformed channel id (not UC + 22) is stored as '' rather than overflowing varchar(24). */
    private static function channelId(mixed $value): string
    {
        return is_string($value) && preg_match(ChannelInput::ID_PATTERN, $value) === 1 ? $value : '';
    }

    private static function title(mixed $value): string
    {
        return mb_substr(self::str($value), 0, 200);
    }

    private static function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?(?:Z|[+-]\d{2}:\d{2})$/D', $value) !== 1) {
            return null;
        }
        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param list<string> $prefixes */
    private static function thumbnail(mixed $thumbnails, array $prefixes): ?string
    {
        if (! is_array($thumbnails)) {
            return null;
        }
        foreach (['medium', 'default', 'high'] as $size) {
            $url = $thumbnails[$size]['url'] ?? null;
            if (! is_string($url) || strlen($url) > self::MAX_COLUMN) {
                continue;
            }
            foreach ($prefixes as $prefix) {
                if (str_starts_with($url, $prefix)) {
                    return $url;
                }
            }
        }

        return null;
    }
}

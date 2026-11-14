<?php

namespace App\Services\YouTube;

use App\Support\ChannelRef;
use App\Support\IsoDuration;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class YouTubeReader
{
    public const BASE_URL = 'https://www.googleapis.com/youtube/v3/';

    private const CHANNEL_THUMB_PREFIXES = ['https://yt3.ggpht.com/', 'https://i.ytimg.com/'];

    private const VIDEO_THUMB_PREFIXES = ['https://i.ytimg.com/'];

    public function __construct(private readonly QuotaLedger $ledger) {}

    public function channel(ChannelRef $ref): ?ChannelInfo
    {
        $query = ['part' => 'snippet,contentDetails'];
        if ($ref->isId()) {
            $query['id'] = $ref->value;
        } else {
            $query['forHandle'] = '@'.$ref->value;
        }

        $item = $this->items($this->get('channels', $query, QuotaLedger::READ))[0] ?? null;
        if ($item === null) {
            return null;
        }
        $id = $item['id'] ?? null;
        $uploads = $item['contentDetails']['relatedPlaylists']['uploads'] ?? null;
        if (! is_string($id) || $id === '' || ! is_string($uploads) || $uploads === '') {
            return null;
        }
        $custom = $item['snippet']['customUrl'] ?? null;

        return new ChannelInfo(
            $id,
            self::str($item['snippet']['title'] ?? null),
            self::thumbnail($item['snippet']['thumbnails'] ?? null, self::CHANNEL_THUMB_PREFIXES),
            $uploads,
            is_string($custom) && $custom !== '' ? mb_strtolower(ltrim($custom, '@')) : null,
        );
    }

    public function uploadsPage(string $playlistId, ?string $pageToken): PlaylistItemsPage
    {
        $query = ['part' => 'snippet,contentDetails,status', 'maxResults' => 50, 'playlistId' => $playlistId];
        if ($pageToken !== null) {
            $query['pageToken'] = $pageToken;
        }

        try {
            $json = $this->get('playlistItems', $query, QuotaLedger::READ);
        } catch (YouTubeApiException $e) {
            if ($e->error->status === 404 && $e->error->reason === 'playlistNotFound') {
                return new PlaylistItemsPage([], null);
            }
            throw $e;
        }

        $items = [];
        foreach ($this->items($json) as $item) {
            $videoId = $item['contentDetails']['videoId'] ?? $item['snippet']['resourceId']['videoId'] ?? null;
            if (! is_string($videoId) || $videoId === '') {
                continue;
            }
            $items[] = new PlaylistItem(
                $videoId,
                self::str($item['snippet']['title'] ?? null),
                self::thumbnail($item['snippet']['thumbnails'] ?? null, self::VIDEO_THUMB_PREFIXES),
                self::date($item['contentDetails']['videoPublishedAt'] ?? null),
                self::str($item['status']['privacyStatus'] ?? null, 'unknown'),
            );
        }
        $next = $json['nextPageToken'] ?? null;

        return new PlaylistItemsPage($items, is_string($next) && $next !== '' ? $next : null);
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, VideoInfo>
     */
    public function videos(array $ids, string $bucket = QuotaLedger::READ): array
    {
        $result = [];
        foreach (array_chunk(array_values(array_unique($ids)), 50) as $chunk) {
            $json = $this->get('videos', [
                'part' => 'snippet,contentDetails,status',
                'id' => implode(',', $chunk),
                'maxResults' => 50,
            ], $bucket);

            foreach ($this->items($json) as $item) {
                $id = $item['id'] ?? null;
                if (! is_string($id) || ! in_array($id, $chunk, true)) {
                    continue;
                }
                $result[$id] = new VideoInfo(
                    $id,
                    self::str($item['snippet']['title'] ?? null),
                    self::thumbnail($item['snippet']['thumbnails'] ?? null, self::VIDEO_THUMB_PREFIXES),
                    self::str($item['snippet']['channelId'] ?? null),
                    self::str($item['snippet']['channelTitle'] ?? null),
                    IsoDuration::toSeconds(is_string($d = $item['contentDetails']['duration'] ?? null) ? $d : null),
                    self::date($item['snippet']['publishedAt'] ?? null),
                    self::str($item['status']['privacyStatus'] ?? null, 'unknown'),
                );
            }
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function get(string $endpoint, array $query, string $bucket): array
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
                ->get(self::BASE_URL.$endpoint, $query);
        } catch (ConnectionException) {
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
    private function items(array $json): array
    {
        $items = $json['items'] ?? [];
        if (! is_array($items)) {
            throw $this->fail('response', YouTubeError::malformed());
        }

        return array_values(array_filter($items, 'is_array'));
    }

    private static function str(mixed $value, string $default = ''): string
    {
        return is_string($value) ? $value : $default;
    }

    private static function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
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
            if (! is_string($url)) {
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

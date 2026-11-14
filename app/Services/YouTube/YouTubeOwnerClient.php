<?php

namespace App\Services\YouTube;

use App\Support\ChannelInput;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * YouTube calls made with the channel owner's OAuth access token (never the API key).
 * Phase 2 reads the owner's own channel and the owner's unlisted uploads (user decision #9);
 * usage is recorded in the owner_read bucket without a cap. Nothing read here is stored.
 */
class YouTubeOwnerClient
{
    private const PAGE_SIZE = 50;

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
     * Paging stops at the same cap as the channel list (fetch_max_videos). Empty for a malformed
     * channel id or a channel without uploads. Never stored in shared caches.
     *
     * @return list<PlaylistItem>
     *
     * @throws YouTubeApiException
     */
    public function unlistedUploads(#[\SensitiveParameter] string $accessToken, string $channelId): array
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
            if ($token === null) {
                break;
            }
        }

        return array_values($unlisted);
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
     * @param  array<string, mixed>  $json
     * @return array<string, mixed>|null
     */
    private function firstItem(array $json, string $endpoint): ?array
    {
        $items = $json['items'] ?? [];
        if (! is_array($items)) {
            throw $this->fail($endpoint, YouTubeError::malformed());
        }

        return array_values(array_filter($items, 'is_array'))[0] ?? null;
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

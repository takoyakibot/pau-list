<?php

namespace App\Services;

use App\Models\ChannelCache;
use App\Services\YouTube\ChannelInfo;
use App\Services\YouTube\PlaylistItem;
use App\Services\YouTube\ReadBudgetExceeded;
use App\Services\YouTube\YouTubeApiException;
use App\Services\YouTube\YouTubeReader;
use App\Support\ChannelRef;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ChannelVideoCatalog
{
    private const PAGE_SIZE = 50;

    public function __construct(private readonly YouTubeReader $reader) {}

    public function load(string $channelId): CatalogResult
    {
        if ($this->expireIfStale($channelId) === null && $this->isReusable($channelId)) {
            return new CatalogResult(CatalogStatus::Reused, $this->find($channelId));
        }

        try {
            return Cache::lock("channel-fetch:{$channelId}", 60)->block(40, function () use ($channelId) {
                // Re-check under the lock: a concurrent request may have just imported this channel.
                $existing = $this->expireIfStale($channelId);
                if ($existing !== null && $this->isReusable($channelId)) {
                    return new CatalogResult(CatalogStatus::Reused, $existing);
                }

                return $this->import($channelId, $existing);
            });
        } catch (LockTimeoutException) {
            return new CatalogResult(CatalogStatus::Failed, $this->live($channelId));
        }
    }

    public function isReusable(string $channelId): bool
    {
        $cache = $this->find($channelId);
        if ($cache === null || $cache->expires_at->lessThanOrEqualTo(now())) {
            return false;
        }

        return $cache->last_synced_at
            ->addMinutes((int) config('youtube.channel.cache_reuse_minutes'))
            ->greaterThanOrEqualTo(now());
    }

    public function expire(ChannelCache $cache): void
    {
        DB::transaction(function () use ($cache) {
            DB::table('channel_cache_videos')->where('channel_id', $cache->channel_id)->delete();
            DB::table('channel_caches')->where('channel_id', $cache->channel_id)->delete();
            DB::table('channel_cache_expirations')->upsert(
                [['channel_id' => $cache->channel_id, 'expired_at' => now()]],
                ['channel_id'],
                ['expired_at'],
            );
        });
    }

    public function expireStale(): int
    {
        $count = 0;
        ChannelCache::query()
            ->where('expires_at', '<=', now())
            ->each(function (ChannelCache $cache) use (&$count) {
                $this->expire($cache);
                $count++;
            });

        return $count;
    }

    private function import(string $channelId, ?ChannelCache $existing): CatalogResult
    {
        $hadExpired = DB::table('channel_cache_expirations')->where('channel_id', $channelId)->exists();

        try {
            $info = $this->reader->channel(ChannelRef::id($channelId));
            if ($info === null || $info->id !== $channelId) {
                return new CatalogResult(CatalogStatus::NotFound, null, $hadExpired);
            }

            [$items, $truncated] = $this->readAll($info);
        } catch (ReadBudgetExceeded) {
            return new CatalogResult(CatalogStatus::BudgetExhausted, $existing, $hadExpired);
        } catch (YouTubeApiException) {
            return new CatalogResult(CatalogStatus::Failed, $existing, $hadExpired);
        }

        $this->writeFull($info, $items, $truncated);

        return new CatalogResult(CatalogStatus::Imported, $this->find($channelId), $hadExpired);
    }

    /** @return array{0: array<string, PlaylistItem>, 1: bool} */
    private function readAll(ChannelInfo $info): array
    {
        $items = [];
        $token = null;
        $pages = 0;
        do {
            if ($pages >= $this->maxPages()) {
                return [$items, true];
            }
            $page = $this->reader->uploadsPage($info->uploadsPlaylistId, $token);
            $pages++;
            foreach ($page->items as $item) {
                if ($item->privacyStatus === 'public') {
                    $items[$item->videoId] ??= $item;
                }
            }
            $token = $page->nextPageToken;
        } while ($token !== null);

        return [$items, false];
    }

    /** @param array<string, PlaylistItem> $items */
    private function writeFull(ChannelInfo $info, array $items, bool $truncated): void
    {
        $now = CarbonImmutable::now();
        DB::transaction(function () use ($info, $items, $truncated, $now) {
            DB::table('channel_caches')->upsert([[
                'channel_id' => $info->id,
                'handle' => $info->handle,
                'title' => $info->title,
                'thumbnail_url' => $info->thumbnailUrl,
                'uploads_playlist_id' => $info->uploadsPlaylistId,
                'meta_fetched_at' => $now,
                'cached_since' => $now,
                'last_synced_at' => $now,
                'truncated' => $truncated,
                'expires_at' => $now->addDays((int) config('listmaker.retention_days')),
            ]], ['channel_id']);
            DB::table('channel_cache_videos')->where('channel_id', $info->id)->delete();
            $this->insertVideos($info->id, $items, $now);
            DB::table('channel_cache_expirations')->where('channel_id', $info->id)->delete();
        });
    }

    /** @param array<string, PlaylistItem> $items */
    private function insertVideos(string $channelId, array $items, CarbonImmutable $now): void
    {
        $rows = array_map(fn (PlaylistItem $item) => [
            'channel_id' => $channelId,
            'video_id' => $item->videoId,
            'title' => $item->title,
            'thumbnail_url' => $item->thumbnailUrl,
            'published_at' => $item->publishedAt?->utc(),
            'fetched_at' => $now,
        ], array_values($items));

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('channel_cache_videos')->insert($chunk);
        }
    }

    /** Expires the channel's cache if it is past expires_at. Returns the still-live row, if any. */
    private function expireIfStale(string $channelId): ?ChannelCache
    {
        $cache = $this->find($channelId);
        if ($cache !== null && $cache->expires_at->lessThanOrEqualTo(now())) {
            $this->expire($cache);

            return null;
        }

        return $cache;
    }

    private function live(string $channelId): ?ChannelCache
    {
        $cache = $this->find($channelId);

        return $cache !== null && $cache->expires_at->greaterThan(now()) ? $cache : null;
    }

    private function find(string $channelId): ?ChannelCache
    {
        return ChannelCache::query()->find($channelId);
    }

    private function maxPages(): int
    {
        return intdiv((int) config('youtube.channel.fetch_max_videos'), self::PAGE_SIZE);
    }
}

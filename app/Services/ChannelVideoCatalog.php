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

    private const DEADLINE_SECONDS = 30;

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
        // Collect ids first: deleting while chunking by offset would skip rows.
        $ids = ChannelCache::query()->where('expires_at', '<=', now())->pluck('channel_id');
        $count = 0;
        foreach ($ids as $id) {
            $cache = $this->find($id);
            if ($cache !== null && $cache->expires_at->lessThanOrEqualTo(now())) {
                $this->expire($cache);
                $count++;
            }
        }

        return $count;
    }

    private function import(string $channelId, ?ChannelCache $existing): CatalogResult
    {
        $hadExpired = DB::table('channel_cache_expirations')->where('channel_id', $channelId)->exists();
        $deadline = CarbonImmutable::now()->addSeconds(self::DEADLINE_SECONDS);

        try {
            $info = $this->reader->channel(ChannelRef::id($channelId));
            if ($info === null || $info->id !== $channelId) {
                return new CatalogResult(CatalogStatus::NotFound, null, $hadExpired);
            }

            $read = $existing === null
                ? $this->readAll($info, $deadline)
                : $this->readDiff($info, $existing, $deadline);
        } catch (ReadBudgetExceeded) {
            return new CatalogResult(CatalogStatus::BudgetExhausted, $existing, $hadExpired);
        } catch (YouTubeApiException) {
            return new CatalogResult(CatalogStatus::Failed, $existing, $hadExpired);
        }

        if ($read === null) {
            // Ran past the 30-second budget: discard everything read so far (spec 5.3 / D21).
            return new CatalogResult(CatalogStatus::Failed, $existing, $hadExpired);
        }

        if ($existing === null) {
            $this->writeFull($info, $read['new'], $read['truncated']);

            return new CatalogResult(CatalogStatus::Imported, $this->find($channelId), $hadExpired);
        }

        $this->writeDiff($info, $read['new'], $read['remove']);

        return new CatalogResult(CatalogStatus::Synced, $this->find($channelId), $hadExpired);
    }

    /**
     * Full import: read from the newest upload until the end or the page cap.
     *
     * @return array{new: array<string, PlaylistItem>, truncated: bool}|null null when the deadline passed
     */
    private function readAll(ChannelInfo $info, CarbonImmutable $deadline): ?array
    {
        $new = [];
        $token = null;
        $pages = 0;
        do {
            if ($pages >= $this->maxPages()) {
                return ['new' => $new, 'truncated' => true];
            }
            if (CarbonImmutable::now()->greaterThan($deadline)) {
                return null;
            }
            $page = $this->reader->uploadsPage($info->uploadsPlaylistId, $token);
            $pages++;
            foreach ($page->items as $item) {
                if ($item->privacyStatus === 'public') {
                    $new[$item->videoId] ??= $item;
                }
            }
            $token = $page->nextPageToken;
        } while ($token !== null);

        return ['new' => $new, 'truncated' => false];
    }

    /**
     * Differential import: read newest first and stop on the page where
     * `sync_known_run` known IDs appear in a row (or all known rows, if fewer).
     *
     * @return array{new: array<string, PlaylistItem>, remove: list<string>}|null null when the deadline passed
     */
    private function readDiff(ChannelInfo $info, ChannelCache $existing, CarbonImmutable $deadline): ?array
    {
        $known = array_flip(DB::table('channel_cache_videos')
            ->where('channel_id', $existing->channel_id)
            ->pluck('video_id')
            ->all());
        $threshold = min((int) config('youtube.channel.sync_known_run'), count($known));

        $new = [];
        $remove = [];
        $token = null;
        $pages = 0;
        while (true) {
            if ($pages >= $this->maxPages()) {
                break;
            }
            if (CarbonImmutable::now()->greaterThan($deadline)) {
                return null;
            }
            $page = $this->reader->uploadsPage($info->uploadsPlaylistId, $token);
            $pages++;

            $run = 0;
            $stop = $threshold === 0;
            foreach ($page->items as $item) {
                $isKnown = isset($known[$item->videoId]);
                if ($item->privacyStatus !== 'public') {
                    if ($isKnown) {
                        $remove[$item->videoId] = true;
                    }

                    continue;
                }
                if ($isKnown) {
                    $run++;
                    if ($run >= $threshold) {
                        $stop = true;
                    }
                } else {
                    $new[$item->videoId] ??= $item;
                    $run = 0;
                }
            }

            $token = $page->nextPageToken;
            if ($stop || $token === null) {
                break;
            }
        }

        return ['new' => $new, 'remove' => array_keys($remove)];
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
            $this->trimToLimit($info->id);
            DB::table('channel_cache_expirations')->where('channel_id', $info->id)->delete();
        });
    }

    /**
     * Adds new rows and removes no-longer-public ones. Existing rows, cached_since,
     * expires_at and truncated are left untouched.
     *
     * @param  array<string, PlaylistItem>  $new
     * @param  list<string>  $remove
     */
    private function writeDiff(ChannelInfo $info, array $new, array $remove): void
    {
        $now = CarbonImmutable::now();
        DB::transaction(function () use ($info, $new, $remove, $now) {
            DB::table('channel_caches')->where('channel_id', $info->id)->update([
                'handle' => $info->handle,
                'title' => $info->title,
                'thumbnail_url' => $info->thumbnailUrl,
                'uploads_playlist_id' => $info->uploadsPlaylistId,
                'meta_fetched_at' => $now,
                'last_synced_at' => $now,
            ]);
            $this->insertVideos($info->id, $new, $now);
            foreach (array_chunk($remove, 200) as $chunk) {
                DB::table('channel_cache_videos')
                    ->where('channel_id', $info->id)
                    ->whereIn('video_id', $chunk)
                    ->delete();
            }
            $this->trimToLimit($info->id);
        });
    }

    /** Deletes rows beyond fetch_max_videos, oldest published_at first (ties: video_id ascending). */
    private function trimToLimit(string $channelId): void
    {
        $excess = DB::table('channel_cache_videos')->where('channel_id', $channelId)->count()
            - (int) config('youtube.channel.fetch_max_videos');
        if ($excess <= 0) {
            return;
        }
        $victims = DB::table('channel_cache_videos')
            ->where('channel_id', $channelId)
            ->orderBy('published_at')
            ->orderBy('video_id')
            ->limit($excess)
            ->pluck('video_id')
            ->all();
        foreach (array_chunk($victims, 200) as $chunk) {
            DB::table('channel_cache_videos')
                ->where('channel_id', $channelId)
                ->whereIn('video_id', $chunk)
                ->delete();
        }
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
        return max(1, intdiv((int) config('youtube.channel.fetch_max_videos'), self::PAGE_SIZE));
    }
}

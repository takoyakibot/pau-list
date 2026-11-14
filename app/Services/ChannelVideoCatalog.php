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
use Carbon\CarbonInterface;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ChannelVideoCatalog
{
    private const PAGE_SIZE = 50;

    private const DEADLINE_SECONDS = 30;

    private const LOCK_SECONDS = 60;

    private const LOCK_WAIT_SECONDS = 40;

    public function __construct(private readonly YouTubeReader $reader) {}

    public function load(string $channelId): CatalogResult
    {
        // Lock-free fast path. isReusable() is false for an expired row; expiring it is left to the locked path.
        $cached = $this->find($channelId);
        if ($cached !== null && $this->isReusable($channelId)) {
            return new CatalogResult(CatalogStatus::Reused, $cached);
        }

        try {
            return $this->lock($channelId)->block(self::LOCK_WAIT_SECONDS, function () use ($channelId) {
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

    /** Channel id from an unexpired cache row for this (lowercased) handle; never uses an expired row. */
    public function cachedChannelIdForHandle(string $handle): ?string
    {
        $id = ChannelCache::query()
            ->where('handle', mb_strtolower(ltrim($handle, '@')))
            ->where('expires_at', '>', now())
            ->orderByDesc('last_synced_at')
            ->value('channel_id');

        return is_string($id) ? $id : null;
    }

    /**
     * Resolves a handle to a channel id: unexpired cache first, otherwise one channels.list call.
     * Writes no cache row. ReadBudgetExceeded / YouTubeApiException propagate.
     */
    public function resolveHandle(string $handle): ?string
    {
        $cached = $this->cachedChannelIdForHandle($handle);
        if ($cached !== null) {
            return $cached;
        }

        return $this->reader->channel(ChannelRef::handle($handle))?->id;
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

    /**
     * Expires every channel whose expires_at <= $cutoff (default: now). The prune batch passes now + 24h so that,
     * running daily, no channel outlives its displayed delete date.
     */
    public function expireStale(?CarbonInterface $cutoff = null): int
    {
        $cutoff ??= now();
        // Collect ids first: deleting while chunking by offset would skip rows.
        $ids = ChannelCache::query()->where('expires_at', '<=', $cutoff)->pluck('channel_id');
        $count = 0;
        $skipped = [];
        foreach ($ids as $id) {
            // Do not wait on a channel being imported right now; retry it after the others.
            $lock = $this->lock($id);
            if (! $lock->get()) {
                $skipped[] = $id;

                continue;
            }
            $count += $this->expireLocked($id, $lock, $cutoff);
        }

        // Imports are bounded (deadline ~30s + writes), so waiting up to the lock TTL lets the batch
        // still delete a channel that was being imported when it started.
        foreach ($skipped as $id) {
            $lock = $this->lock($id);
            try {
                $lock->block(self::LOCK_SECONDS);
            } catch (LockTimeoutException) {
                continue; // the next run (or the next load, once expired) handles it
            }
            $count += $this->expireLocked($id, $lock, $cutoff);
        }

        return $count;
    }

    /** Runs with $lock held and releases it. Re-checks expiry under the lock. */
    private function expireLocked(string $id, Lock $lock, CarbonInterface $cutoff): int
    {
        try {
            $cache = $this->find($id);
            if ($cache !== null && $cache->expires_at->lessThanOrEqualTo($cutoff)) {
                $this->expire($cache);

                return 1;
            }

            return 0;
        } finally {
            $lock->release();
        }
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
            return new CatalogResult(CatalogStatus::BudgetExhausted, $this->live($channelId), $hadExpired);
        } catch (YouTubeApiException) {
            return new CatalogResult(CatalogStatus::Failed, $this->live($channelId), $hadExpired);
        }

        if ($read === null || CarbonImmutable::now()->greaterThan($deadline)) {
            // Ran past the 30-second total (spec 5.3 / 5.7): discard everything read so far (D21).
            return new CatalogResult(CatalogStatus::Failed, $this->live($channelId), $hadExpired);
        }

        if ($existing === null) {
            $this->writeFull($info, $read['new'], $read['truncated']);

            return new CatalogResult(CatalogStatus::Imported, $this->find($channelId), $hadExpired);
        }

        if (! $this->writeDiff($info, $read['new'], $read['remove'])) {
            // The row expired (or was expired by someone else) while we were reading. Never show or extend
            // an expired list: make sure it is expired and recorded so the next load is a full import.
            if (($stale = $this->find($channelId)) !== null) {
                $this->expire($stale);
            }

            return new CatalogResult(CatalogStatus::Failed, null, $hadExpired);
        }

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
    private function writeDiff(ChannelInfo $info, array $new, array $remove): bool
    {
        $now = CarbonImmutable::now();

        return DB::transaction(function () use ($info, $new, $remove, $now) {
            $updated = DB::table('channel_caches')
                ->where('channel_id', $info->id)
                ->where('expires_at', '>', $now)
                ->update([
                    'handle' => $info->handle,
                    'title' => $info->title,
                    'thumbnail_url' => $info->thumbnailUrl,
                    'uploads_playlist_id' => $info->uploadsPlaylistId,
                    'meta_fetched_at' => $now,
                    'last_synced_at' => $now,
                ]);
            if ($updated === 0) {
                return false;
            }
            $this->insertVideos($info->id, $new, $now);
            foreach (array_chunk($remove, 200) as $chunk) {
                DB::table('channel_cache_videos')
                    ->where('channel_id', $info->id)
                    ->whereIn('video_id', $chunk)
                    ->delete();
            }
            $this->trimToLimit($info->id);

            return true;
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

    private function lock(string $channelId): Lock
    {
        return Cache::lock("channel-fetch:{$channelId}", self::LOCK_SECONDS);
    }

    private function maxPages(): int
    {
        return max(1, intdiv((int) config('youtube.channel.fetch_max_videos'), self::PAGE_SIZE));
    }
}

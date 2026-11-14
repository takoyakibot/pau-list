<?php

namespace App\Services;

use App\Models\ChannelCache;
use App\Models\Proposal;
use App\Models\ProposalItem;
use App\Services\YouTube\ReadBudgetExceeded;
use App\Services\YouTube\YouTubeApiException;
use App\Services\YouTube\YouTubeReader;
use App\Support\ChannelRef;
use App\Support\ProposalKey;
use Illuminate\Support\Facades\DB;

class ProposalCreator
{
    public function __construct(private readonly YouTubeReader $reader) {}

    /**
     * Re-fetches every video and snapshots only server-side values (client titles are ignored).
     *
     * @param  array{title: string, description?: ?string, comment?: ?string, proposer_name?: ?string, video_ids: list<string>}  $validated
     *
     * @throws ChannelCacheMissing
     * @throws ChannelUnavailable
     * @throws UnavailableVideos
     * @throws ReadBudgetExceeded
     * @throws YouTubeApiException
     */
    public function create(string $channelId, array $validated): CreatedProposal
    {
        $channel = ChannelCache::query()
            ->whereKey($channelId)
            ->where('expires_at', '>', now())
            ->first();
        if ($channel === null) {
            throw new ChannelCacheMissing;
        }
        $channel = $this->freshMeta($channel);
        // Always the id stored in the row: a case-insensitive DB collation may match a differently-cased route id.
        $channelId = $channel->channel_id;

        $ids = array_values($validated['video_ids']);
        $videos = $this->reader->videos($ids);

        $unavailable = array_values(array_filter(
            $ids,
            fn (string $id) => ! isset($videos[$id]) || ! $videos[$id]->isPublic(),
        ));
        if ($unavailable !== []) {
            throw new UnavailableVideos($unavailable);
        }

        $key = ProposalKey::generate();
        $now = now();

        $proposal = DB::transaction(function () use ($channel, $channelId, $validated, $ids, $videos, $key, $now) {
            $proposal = Proposal::create([
                'key_hash' => ProposalKey::hash($key),
                'channel_id' => $channelId,
                'channel_title' => $channel->title,
                'channel_thumbnail_url' => $channel->thumbnail_url,
                'title' => $validated['title'],
                'description' => $validated['description'] ?? '',
                'comment' => $validated['comment'] ?? null,
                'proposer_name' => $validated['proposer_name'] ?? null,
                'item_count' => count($ids),
                'created_at' => $now,
                'expires_at' => $now->copy()->addDays((int) config('listmaker.retention_days')),
            ]);

            $rows = [];
            foreach ($ids as $position => $id) {
                $v = $videos[$id];
                $rows[] = [
                    'proposal_id' => $proposal->id,
                    'position' => $position,
                    'video_id' => $v->id,
                    'video_channel_id' => $v->channelId,
                    'video_channel_title' => $v->channelTitle,
                    'is_outside_channel' => $v->channelId !== $channelId,
                    'title' => $v->title,
                    'thumbnail_url' => $v->thumbnailUrl,
                    'duration_seconds' => $v->durationSeconds,
                    'published_at' => $v->publishedAt?->utc(),
                ];
            }
            ProposalItem::insert($rows);

            return $proposal;
        });

        return new CreatedProposal($proposal, $key);
    }

    /**
     * The channel title/icon copied into a proposal must not be older than the reuse window
     * (the list itself may be up to 30 days old). Re-fetches with one read unit when stale and
     * updates the cache row's metadata only (the list, last_synced_at and expires_at are untouched).
     *
     * @throws ChannelUnavailable
     * @throws ReadBudgetExceeded
     * @throws YouTubeApiException
     */
    private function freshMeta(ChannelCache $channel): ChannelCache
    {
        $fresh = $channel->meta_fetched_at
            ->addMinutes((int) config('youtube.channel.cache_reuse_minutes'))
            ->greaterThanOrEqualTo(now());
        if ($fresh) {
            return $channel;
        }

        $info = $this->reader->channel(ChannelRef::id($channel->channel_id));
        if ($info === null || $info->id !== $channel->channel_id) {
            throw new ChannelUnavailable;
        }

        $channel->forceFill([
            'handle' => $info->handle,
            'title' => $info->title,
            'thumbnail_url' => $info->thumbnailUrl,
            'meta_fetched_at' => now(),
        ])->save();

        return $channel;
    }
}

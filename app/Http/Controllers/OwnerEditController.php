<?php

namespace App\Http\Controllers;

use App\Models\Proposal;
use App\Services\ChannelVideoCatalog;
use App\Services\YouTube\PlaylistItem;
use App\Services\YouTube\QuotaLedger;
use App\Services\YouTube\YouTubeApiException;
use App\Services\YouTube\YouTubeOwnerClient;
use App\Support\OwnerLogin;
use App\Support\QuotaPolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * S5: the owner's edit screen (display only in Phase 2; no writes, no form).
 * Candidates are the channel's public list (shared cache) plus the owner's own unlisted uploads,
 * which are read with the owner token and only rendered into this response (user decision #9).
 */
class OwnerEditController extends Controller
{
    public const CATALOG_UNAVAILABLE = 'チャンネルの動画一覧を読み込めませんでした。動画 URL で追加してください';

    public const UNLISTED_SHOWN = 'あなたの限定公開の動画も一覧に表示しています（あなたにだけ表示されます）';

    public const UNLISTED_FAILED = 'あなたの限定公開の動画を読み込めませんでした。公開動画の一覧と動画 URL で追加できます';

    public function show(Request $request, ChannelVideoCatalog $catalog, QuotaLedger $ledger, YouTubeOwnerClient $youtube): Response
    {
        /** @var Proposal $proposal */
        $proposal = $request->attributes->get('proposal');
        /** @var OwnerLogin $owner */
        $owner = $request->attributes->get('owner');

        $channel = $catalog->load($proposal->channel_id)->channel;
        $public = $channel === null ? [] : $channel->videos()
            ->orderByRaw('published_at is null')
            ->orderByDesc('published_at')
            ->orderBy('video_id')
            ->get()
            ->map(fn ($v) => [
                'id' => $v->video_id,
                'title' => $v->title,
                'thumbnail_url' => $v->thumbnail_url,
                'published_at' => $v->published_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            ])->all();

        try {
            $unlisted = $youtube->unlistedUploads($owner->accessToken, $proposal->channel_id);
            $unlistedFailed = false;
        } catch (YouTubeApiException) {
            $unlisted = [];
            $unlistedFailed = true;
        }

        $items = $proposal->items()->get();
        $remaining = $ledger->remainingToday();
        $count = $items->count();
        $resetLabel = $remaining->resetAtJst->format('n月j日 G時');

        $editor = [
            'maxItems' => QuotaPolicy::maxItemsPerPlaylist(),
            'remainingItems' => max(0, $remaining->items),
            'resetLabel' => $resetLabel,
            'lookupUrl' => route('channels.video', $proposal->channel_id),
            // A prefix only: enough to keep drafts apart, never the full hash or the raw key.
            'draftId' => substr($proposal->key_hash, 0, 16),
            'items' => $items->map(fn ($item) => [
                'id' => $item->video_id,
                'title' => $item->title,
                'thumbnail_url' => $item->thumbnail_url,
                'outside' => (bool) $item->is_outside_channel,
            ])->values()->all(),
            'videos' => self::withUnlisted($public, $unlisted),
            'title' => $proposal->title,
            'description' => $proposal->description,
            'privacy' => 'private',
        ];

        return response()->view('owner.edit', [
            'proposal' => $proposal,
            'ownerChannelTitle' => $owner->channelTitle,
            'items' => $items,
            'editor' => $editor,
            'remaining' => $remaining,
            'resetLabel' => $resetLabel,
            'count' => $count,
            'shortBy' => max(0, $count - $remaining->items),
            'catalogUnavailable' => $channel === null,
            'unlistedShown' => $unlisted !== [],
            'unlistedFailed' => $unlistedFailed,
        ]);
    }

    /**
     * Merges the owner's unlisted uploads into the public list, newest first (undated last, then by id).
     * A cached video that has since become unlisted appears once, as unlisted.
     *
     * @param  list<array<string, mixed>>  $public
     * @param  list<PlaylistItem>  $unlisted
     * @return list<array<string, mixed>>
     */
    private static function withUnlisted(array $public, array $unlisted): array
    {
        if ($unlisted === []) {
            return $public;
        }
        $videos = [];
        foreach ($public as $video) {
            $videos[$video['id']] = $video;
        }
        foreach ($unlisted as $item) {
            $videos[$item->videoId] = [
                'id' => $item->videoId,
                'title' => $item->title,
                'thumbnail_url' => $item->thumbnailUrl,
                'published_at' => $item->publishedAt?->utc()->format('Y-m-d\TH:i:s\Z'),
                'unlisted' => true,
            ];
        }
        $videos = array_values($videos);
        usort($videos, fn (array $a, array $b) => [$a['published_at'] === null, $b['published_at'] ?? '', $a['id']]
            <=> [$b['published_at'] === null, $a['published_at'] ?? '', $b['id']]);

        return $videos;
    }
}

<?php

namespace App\Http\Controllers;

use App\Http\Middleware\RequireOwnerSession;
use App\Models\Proposal;
use App\Services\ChannelVideoCatalog;
use App\Services\YouTube\PlaylistItem;
use App\Services\YouTube\QuotaLedger;
use App\Services\YouTube\YouTubeApiException;
use App\Services\YouTube\YouTubeOwnerClient;
use App\Support\OwnerLogin;
use App\Support\OwnerSession;
use App\Support\QuotaPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * S5: the owner's edit screen (display only in Phase 2; no writes, no form).
 * Candidates are the channel's public list (shared cache). The owner's own unlisted uploads (user
 * decision #9) come from unlisted() on demand and are kept only in the owner's session and the response.
 */
class OwnerEditController extends Controller
{
    public const CATALOG_UNAVAILABLE = 'チャンネルの動画一覧を読み込めませんでした。動画 URL で追加してください';

    public const UNLISTED_NOTE = 'あなたの限定公開の動画はあなたにだけ表示されます（非公開の動画は表示されません）';

    public const UNLISTED_FAILED = 'あなたの限定公開の動画を読み込めませんでした。公開動画の一覧と動画 URL で追加できます';

    public const UNLISTED_THROTTLED = '限定公開の動画の読み込みが続いています。10 分ほど待ってから再度お試しください';

    public function show(Request $request, ChannelVideoCatalog $catalog, QuotaLedger $ledger): Response
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
            'videos' => $public,
            // The owner's unlisted uploads are loaded only on demand (button), never on page load.
            'unlistedUrl' => route('owner.unlisted'),
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
        ]);
    }

    /**
     * JSON for the S5 button 「限定公開の動画も表示する」: the logged-in owner's unlisted uploads only.
     * Same-origin fetch only, rate limited per owner channel, cached in the (encrypted) session for
     * 10 minutes, and skipped when today's owner_read reaches QuotaPolicy::ownerReadGuard().
     */
    public function unlisted(OwnerSession $owners, QuotaLedger $ledger, YouTubeOwnerClient $youtube): JsonResponse
    {
        $owner = $owners->current();
        if ($owner === null) {
            return response()->json(['message' => RequireOwnerSession::NOTICE], 401);
        }

        $cached = $owners->unlisted($owner->channelId);
        if ($cached !== null) {
            return response()->json(['videos' => $cached]);
        }

        if ($ledger->used(QuotaLedger::OWNER_READ) >= QuotaPolicy::ownerReadGuard()) {
            return response()->json(['message' => self::UNLISTED_FAILED], 503);
        }

        try {
            $items = $youtube->unlistedUploads($owner->accessToken, $owner->channelId);
        } catch (YouTubeApiException) {
            return response()->json(['message' => self::UNLISTED_FAILED], 503);
        }

        $videos = array_map(fn (PlaylistItem $item) => [
            'id' => $item->videoId,
            'title' => $item->title,
            'thumbnail_url' => $item->thumbnailUrl,
            'published_at' => $item->publishedAt?->utc()->format('Y-m-d\TH:i:s\Z'),
            'unlisted' => true,
        ], $items);
        $owners->rememberUnlisted($owner->channelId, $videos);

        return response()->json(['videos' => $videos]);
    }
}

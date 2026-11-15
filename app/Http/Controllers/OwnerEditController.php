<?php

namespace App\Http\Controllers;

use App\Http\Middleware\RequireOwnerSession;
use App\Models\Proposal;
use App\Services\ChannelVideoCatalog;
use App\Services\PublicVideoLookup;
use App\Services\YouTube\PlaylistItem;
use App\Services\YouTube\QuotaLedger;
use App\Services\YouTube\ReadBudgetExceeded;
use App\Services\YouTube\YouTubeApiException;
use App\Services\YouTube\YouTubeOwnerClient;
use App\Support\OwnerLogin;
use App\Support\OwnerSession;
use App\Support\QuotaPolicy;
use App\Support\VideoUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * S5: the owner's edit screen. 「作成」 posts JSON to proposals.creation.start (owner-edit.js); no form.
 * Candidates are the channel's public list (shared cache). The owner's own unlisted uploads (user
 * decision #9) come from unlisted() on demand and are kept only in the owner's session and the response,
 * or one at a time by URL from lookup() (response only). Neither ever reaches a shared cache.
 */
class OwnerEditController extends Controller
{
    public const CATALOG_UNAVAILABLE = 'チャンネルの動画一覧を読み込めませんでした。動画 URL で追加してください';

    public const UNLISTED_NOTE = 'あなたの限定公開の動画はあなたにだけ表示されます（非公開の動画は表示されません）';

    public const UNLISTED_FAILED = 'あなたの限定公開の動画を読み込めませんでした。公開動画の一覧と動画 URL で追加できます';

    public const UNLISTED_TRUNCATED = '限定公開の動画は新しい順に300本まで表示しています';

    public const UNLISTED_THROTTLED = '限定公開の動画の読み込みが続いています。10 分ほど待ってから再度お試しください';

    public const LOOKUP_NOT_ADDABLE = 'この動画は追加できません（削除・非公開、またはあなたのチャンネル以外の限定公開の動画です）';

    public const LOOKUP_UNLISTED_FAILED = 'この動画を確認できませんでした。時間をおいて再度お試しください';

    /** Shown under the URL input on S5. */
    public const URL_UNLISTED_HINT = 'あなたの限定公開の動画は、URL を入力するか「限定公開の動画も表示する」から追加できます';

    public function show(Request $request, ChannelVideoCatalog $catalog, QuotaLedger $ledger): Response|RedirectResponse
    {
        /** @var Proposal $proposal */
        $proposal = $request->attributes->get('proposal');
        // Once started (or finished), the creation is the owner's page for this proposal (spec 7.5).
        if ($proposal->creation()->exists()) {
            return redirect()->route('proposals.creation', (string) $request->route('key'));
        }
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
            // Relative to /p/{key}/edit (proposals.video, owner only): the raw key is never written into the page.
            'lookupUrl' => 'videos/lookup',
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
            // POST target of 「作成」, relative to /p/{key}/edit: the raw key is never written into the page.
            'creationUrl' => 'creation',
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
     * At most OwnerSession::UNLISTED_MAX (300) newest are returned; `truncated` (+ `notice`) says when more exist.
     */
    public function unlisted(OwnerSession $owners, QuotaLedger $ledger, YouTubeOwnerClient $youtube): JsonResponse
    {
        $owner = $owners->current();
        if ($owner === null) {
            return RequireOwnerSession::loginRequiredJson();
        }

        $cached = $owners->unlisted($owner->channelId);
        if ($cached !== null) {
            return self::unlistedJson($cached['videos'], $cached['truncated']);
        }

        if ($ledger->used(QuotaLedger::OWNER_READ) >= QuotaPolicy::ownerReadGuard()) {
            return response()->json(['message' => self::UNLISTED_FAILED], 503);
        }

        try {
            $items = $youtube->unlistedUploads($owner->accessToken, $owner->channelId, OwnerSession::UNLISTED_MAX);
        } catch (YouTubeApiException $e) {
            // Google 401: the token is gone → logged out and asked to log in again (JS shows the link).
            return RequireOwnerSession::ownerApiFailureJson($e, $owners, self::UNLISTED_FAILED);
        }

        $videos = array_map(fn (PlaylistItem $item) => [
            'id' => $item->videoId,
            'title' => $item->title,
            'thumbnail_url' => $item->thumbnailUrl,
            'published_at' => $item->publishedAt?->utc()->format('Y-m-d\TH:i:s\Z'),
            'unlisted' => true,
        ], $items);
        // Newest first (stable: YouTube's order is kept for equal or missing dates), then the cap.
        usort($videos, fn (array $a, array $b) => ($b['published_at'] ?? '') <=> ($a['published_at'] ?? ''));
        $truncated = count($videos) > OwnerSession::UNLISTED_MAX;
        $videos = array_slice($videos, 0, OwnerSession::UNLISTED_MAX);
        $owners->rememberUnlisted($owner->channelId, $videos, $truncated);

        return self::unlistedJson($videos, $truncated);
    }

    /**
     * JSON for adding one video by URL on S5 (proposals.video, behind RequireOwnerSession). In order:
     * 1. the owner's unlisted list already loaded into this session (no API call);
     * 2. the public lookup shared with proposers (API key, read budget, shared 10-minute cache of public videos);
     * 3. only when that finds nothing: videos.list with the owner's token (owner_read 1, skipped once
     *    QuotaPolicy::ownerReadGuard() is reached, like unlisted()). Accepted only when the video is the logged-in
     *    owner's own `unlisted` upload (spec 7.2's final check uses the same rule); anything else (another
     *    channel's unlisted, private, deleted) is 404 LOOKUP_NOT_ADDABLE.
     * An unlisted result is marked `unlisted: true` like unlisted() and lives only in this response (and then in
     * the page and its draft, and in the creation items if the owner creates the playlist). It is never put into
     * the shared `video-lookup:{id}` cache nor into the session's unlisted list (which stays a whole-list cache).
     */
    public function lookup(
        Request $request,
        OwnerSession $owners,
        PublicVideoLookup $public,
        QuotaLedger $ledger,
        YouTubeOwnerClient $youtube,
    ): JsonResponse {
        /** @var OwnerLogin $owner */
        $owner = $request->attributes->get('owner');
        $url = $request->query('url');
        $videoId = is_string($url) ? VideoUrl::extractId($url) : null;
        if ($videoId === null) {
            return response()->json(['message' => ChannelController::VIDEO_URL_INVALID], 422);
        }

        foreach ($owners->unlisted($owner->channelId)['videos'] ?? [] as $listed) {
            if (($listed['id'] ?? null) === $videoId) {
                return self::ownerUnlistedJson($owner, [
                    'id' => $videoId,
                    'title' => is_string($listed['title'] ?? null) ? $listed['title'] : '',
                    'thumbnail_url' => is_string($listed['thumbnail_url'] ?? null) ? $listed['thumbnail_url'] : null,
                    'published_at' => is_string($listed['published_at'] ?? null) ? $listed['published_at'] : null,
                ]);
            }
        }

        try {
            $video = $public->find($videoId);
        } catch (ReadBudgetExceeded) {
            return response()->json(['message' => '本日の取得上限に達しました'], 503);
        } catch (YouTubeApiException) {
            return response()->json(['message' => 'YouTube との通信に失敗しました。時間をおいて再度お試しください'], 503);
        }
        if ($video !== null) {
            return response()->json(['video' => $video + ['outside' => $video['channel_id'] !== $owner->channelId]]);
        }

        if ($ledger->used(QuotaLedger::OWNER_READ) >= QuotaPolicy::ownerReadGuard()) {
            return response()->json(['message' => self::LOOKUP_UNLISTED_FAILED], 503);
        }
        try {
            $info = $youtube->videos($owner->accessToken, [$videoId])[$videoId] ?? null;
        } catch (YouTubeApiException $e) {
            return RequireOwnerSession::ownerApiFailureJson($e, $owners, self::LOOKUP_UNLISTED_FAILED);
        }
        if ($info === null || $info->channelId !== $owner->channelId || $info->privacyStatus !== 'unlisted') {
            return response()->json(['message' => self::LOOKUP_NOT_ADDABLE], 404);
        }

        return self::ownerUnlistedJson($owner, [
            'id' => $info->id,
            'title' => $info->title,
            'thumbnail_url' => $info->thumbnailUrl,
            'published_at' => $info->publishedAt?->utc()->format('Y-m-d\TH:i:s\Z'),
        ]);
    }

    /** @param array{id: string, title: string, thumbnail_url: ?string, published_at: ?string} $video */
    private static function ownerUnlistedJson(OwnerLogin $owner, array $video): JsonResponse
    {
        return response()->json(['video' => [
            'id' => $video['id'],
            'title' => $video['title'],
            'thumbnail_url' => $video['thumbnail_url'],
            'channel_id' => $owner->channelId,
            'channel_title' => $owner->channelTitle,
            'published_at' => $video['published_at'],
            'outside' => false,
            'unlisted' => true,
        ]]);
    }

    /** @param list<array<string, mixed>> $videos */
    private static function unlistedJson(array $videos, bool $truncated): JsonResponse
    {
        $body = ['videos' => $videos, 'truncated' => $truncated];
        if ($truncated) {
            $body['notice'] = self::UNLISTED_TRUNCATED;
        }

        return response()->json($body);
    }
}

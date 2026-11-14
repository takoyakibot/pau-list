<?php

namespace App\Http\Controllers;

use App\Services\CatalogStatus;
use App\Services\ChannelVideoCatalog;
use App\Services\YouTube\QuotaLedger;
use App\Support\ChannelFetchThrottle;
use App\Support\QuotaPolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ProposalController extends Controller
{
    public function create(
        Request $request,
        string $channelId,
        ChannelVideoCatalog $catalog,
        ChannelFetchThrottle $throttle,
        QuotaLedger $ledger,
    ): Response {
        // Only a request that needs the YouTube API consumes the per-IP fetch limit.
        if (! $catalog->isReusable($channelId) && ! $throttle->attempt($request)) {
            abort(429, '', ['Retry-After' => (string) $throttle->retryAfter($request)]);
        }

        $result = $catalog->load($channelId);

        if ($result->status === CatalogStatus::NotFound) {
            return response()->view('proposals.catalog-unavailable', [
                'heading' => 'チャンネルが見つかりません',
                'detail' => null,
                'hadExpired' => false,
            ], 404);
        }

        $channel = $result->channel;
        if ($channel === null) {
            $budget = $result->status === CatalogStatus::BudgetExhausted;

            return response()->view('proposals.catalog-unavailable', [
                'heading' => $budget
                    ? '本日の取得上限に達しました'
                    : 'YouTube との通信に失敗しました。時間をおいて再度お試しください',
                'detail' => null,
                'hadExpired' => $budget && $result->hadExpired,
            ], 503);
        }

        $maxItems = QuotaPolicy::maxItemsPerPlaylist();
        $videos = $channel->videos()
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

        $payload = [
            'channelId' => $channelId,
            'maxItems' => $maxItems,
            'lookupUrl' => route('channels.video', $channelId),
            'videos' => $videos,
            'unavailableIds' => array_values((array) session('unavailable_video_ids', [])),
        ];

        return response()->view('proposals.create', [
            'channel' => $channel,
            'payload' => $payload,
            'remaining' => $ledger->remainingToday(),
            'staleness' => match ($result->status) {
                CatalogStatus::BudgetExhausted => '（本日の取得上限に達したため更新していません）',
                CatalogStatus::Failed => '（更新できませんでした）',
                default => null,
            },
            'reimported' => $result->status === CatalogStatus::Imported && $result->hadExpired,
        ]);
    }
}

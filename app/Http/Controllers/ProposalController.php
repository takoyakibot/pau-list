<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProposalRequest;
use App\Services\CatalogStatus;
use App\Services\ChannelCacheMissing;
use App\Services\ChannelVideoCatalog;
use App\Services\ProposalCreator;
use App\Services\UnavailableVideos;
use App\Services\YouTube\QuotaLedger;
use App\Services\YouTube\ReadBudgetExceeded;
use App\Services\YouTube\YouTubeApiException;
use App\Support\ChannelFetchThrottle;
use App\Support\QuotaPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

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

    public function store(StoreProposalRequest $request, string $channelId, ProposalCreator $creator): RedirectResponse
    {
        if ($request->isHoneypotHit()) {
            return redirect()->route('home');
        }

        try {
            $created = $creator->create($channelId, $request->safe()->except('website'));
        } catch (ChannelCacheMissing) {
            return redirect()->route('channels.propose', $channelId)
                ->withInput()
                ->withErrors(['video_ids' => '一覧の保存期限が切れたため開き直しました。もう一度保存してください']);
        } catch (UnavailableVideos $e) {
            return back()->withInput()
                ->withErrors(['video_ids' => 'この動画は削除または非公開になっています。外して保存してください'])
                ->with('unavailable_video_ids', $e->ids);
        } catch (ReadBudgetExceeded) {
            return back()->withInput()
                ->withErrors(['video_ids' => '本日の取得上限に達したため、いまは保存できません。時間をおいて再度お試しください']);
        } catch (YouTubeApiException) {
            return back()->withInput()
                ->withErrors(['video_ids' => 'YouTube との通信に失敗しました。時間をおいて再度お試しください']);
        }

        // Never log the raw key or the full hash.
        Log::info('Proposal saved', [
            'key_hash_prefix' => substr($created->proposal->key_hash, 0, 8),
            'items' => $created->proposal->item_count,
        ]);

        session()->flash('proposal_key_shown', $created->key);

        return redirect()->route('proposals.shared', $created->key);
    }

    public function shared(string $key): never
    {
        // Implemented in Task 14.
        abort(404);
    }
}

<?php

namespace App\Http\Controllers;

use App\Services\ChannelVideoCatalog;
use App\Services\YouTube\ReadBudgetExceeded;
use App\Services\YouTube\YouTubeApiException;
use App\Services\YouTube\YouTubeReader;
use App\Support\ChannelFetchThrottle;
use App\Support\ChannelInput;
use App\Support\RateLimitLog;
use App\Support\VideoUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ChannelController extends Controller
{
    public const INVALID_MESSAGE = 'チャンネル URL・ハンドル（@…）・チャンネル ID（UC…）を入力してください。'
        .'使える形式は @ から始まるハンドル（例: @example）、UC から始まるチャンネル ID、'
        .'youtube.com/channel/UC… または youtube.com/@… の URL です'
        .'（@ のないハンドルや、/c/・/user/ の URL は使えません）';

    public function open(
        Request $request,
        ChannelVideoCatalog $catalog,
        ChannelFetchThrottle $throttle,
    ): RedirectResponse {
        $q = $request->query('q');
        if (! is_string($q) || trim($q) === '' || mb_strlen($q) > ChannelInput::MAX_LENGTH) {
            return $this->back(self::INVALID_MESSAGE, $request);
        }

        $ref = ChannelInput::parse($q);
        if ($ref === null) {
            return $this->back(self::INVALID_MESSAGE, $request);
        }
        if ($ref->isId()) {
            return redirect()->route('channels.propose', $ref->value);
        }

        $id = $catalog->cachedChannelIdForHandle($ref->value);
        if ($id === null) {
            // Only a lookup that needs the YouTube API consumes the per-IP fetch limit.
            if (! $throttle->attempt($request)) {
                RateLimitLog::exceeded('channel-fetch');
                abort(429, '', ['Retry-After' => (string) $throttle->retryAfter($request)]);
            }
            try {
                $id = $catalog->resolveHandle($ref->value);
            } catch (ReadBudgetExceeded) {
                return $this->back('本日の取得上限に達しました。時間をおいて再度お試しください', $request);
            } catch (YouTubeApiException) {
                return $this->back('YouTube との通信に失敗しました。時間をおいて再度お試しください', $request);
            }
            if ($id === null) {
                return $this->back('チャンネルが見つかりません', $request);
            }
        }

        return redirect()->route('channels.propose', $id);
    }

    public function video(Request $request, string $channelId, YouTubeReader $reader): JsonResponse
    {
        $url = $request->query('url');
        $videoId = is_string($url) ? VideoUrl::extractId($url) : null;
        if ($videoId === null) {
            return response()->json(['message' => '動画の URL を確認してください'], 422);
        }

        try {
            // Not-found results are cached too; budget/transport failures throw and are not.
            $hit = Cache::remember("video-lookup:{$videoId}", 600, function () use ($reader, $videoId) {
                $info = $reader->videos([$videoId])[$videoId] ?? null;
                if ($info === null || ! $info->isPublic()) {
                    return ['found' => false];
                }

                return ['found' => true, 'video' => [
                    'id' => $info->id,
                    'title' => $info->title,
                    'thumbnail_url' => $info->thumbnailUrl,
                    'channel_id' => $info->channelId,
                    'channel_title' => $info->channelTitle,
                    'published_at' => $info->publishedAt?->utc()->format('Y-m-d\TH:i:s\Z'),
                ]];
            });
        } catch (ReadBudgetExceeded) {
            return response()->json(['message' => '本日の取得上限に達しました'], 503);
        } catch (YouTubeApiException) {
            return response()->json(['message' => 'YouTube との通信に失敗しました。時間をおいて再度お試しください'], 503);
        }

        if (! $hit['found']) {
            return response()->json(['message' => 'この動画は削除または非公開になっています'], 404);
        }

        return response()->json(['video' => $hit['video'] + ['outside' => $hit['video']['channel_id'] !== $channelId]]);
    }

    private function back(string $message, Request $request): RedirectResponse
    {
        $input = $request->query('q');

        return redirect()->route('home')
            ->withErrors(['q' => $message])
            ->withInput(['q' => is_string($input) ? mb_substr($input, 0, ChannelInput::MAX_LENGTH) : '']);
    }
}

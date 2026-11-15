<?php

namespace App\Http\Controllers;

use App\Services\ChannelVideoCatalog;
use App\Services\PublicVideoLookup;
use App\Services\YouTube\ReadBudgetExceeded;
use App\Services\YouTube\YouTubeApiException;
use App\Support\ChannelFetchThrottle;
use App\Support\ChannelInput;
use App\Support\RateLimitLog;
use App\Support\VideoUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ChannelController extends Controller
{
    public const INVALID_MESSAGE = 'チャンネル URL・ハンドル（@…）・チャンネル ID（UC…）を入力してください。'
        .'使える形式は @ から始まるハンドル（例: @example）、UC から始まるチャンネル ID、'
        .'youtube.com/channel/UC… または youtube.com/@… の URL です'
        .'（@ のないハンドルや、/c/・/user/ の URL は使えません）';

    public const VIDEO_URL_INVALID = '動画の URL を確認してください';

    /**
     * The API key cannot tell deleted, private and unlisted videos apart, so one message covers all three.
     * The owner's own unlisted videos can be added on S5 (OwnerEditController::lookup, user decision #9).
     */
    public const VIDEO_NOT_ADDABLE = 'この動画は追加できません（削除・非公開・限定公開の動画は追加できません。'
        .'限定公開の動画は、チャンネルの持ち主がログインしたあとの編集画面で追加できます）';

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

    public function video(Request $request, string $channelId, PublicVideoLookup $lookup): JsonResponse
    {
        $url = $request->query('url');
        $videoId = is_string($url) ? VideoUrl::extractId($url) : null;
        if ($videoId === null) {
            return response()->json(['message' => self::VIDEO_URL_INVALID], 422);
        }

        try {
            $video = $lookup->find($videoId);
        } catch (ReadBudgetExceeded) {
            return response()->json(['message' => '本日の取得上限に達しました'], 503);
        } catch (YouTubeApiException) {
            return response()->json(['message' => 'YouTube との通信に失敗しました。時間をおいて再度お試しください'], 503);
        }

        if ($video === null) {
            return response()->json(['message' => self::VIDEO_NOT_ADDABLE], 404);
        }

        return response()->json(['video' => $video + ['outside' => $video['channel_id'] !== $channelId]]);
    }

    private function back(string $message, Request $request): RedirectResponse
    {
        $input = $request->query('q');

        return redirect()->route('home')
            ->withErrors(['q' => $message])
            ->withInput(['q' => is_string($input) ? mb_substr($input, 0, ChannelInput::MAX_LENGTH) : '']);
    }
}

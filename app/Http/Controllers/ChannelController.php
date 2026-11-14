<?php

namespace App\Http\Controllers;

use App\Services\ChannelVideoCatalog;
use App\Services\YouTube\ReadBudgetExceeded;
use App\Services\YouTube\YouTubeApiException;
use App\Support\ChannelFetchThrottle;
use App\Support\ChannelInput;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

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

    private function back(string $message, Request $request): RedirectResponse
    {
        $input = $request->query('q');

        return redirect()->route('home')
            ->withErrors(['q' => $message])
            ->withInput(['q' => is_string($input) ? mb_substr($input, 0, ChannelInput::MAX_LENGTH) : '']);
    }
}

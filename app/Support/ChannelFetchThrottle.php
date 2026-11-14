<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Limits requests that need a YouTube API call to resolve a channel handle (per IP).
 * Both windows must have room; otherwise nothing is consumed.
 */
class ChannelFetchThrottle
{
    private const MINUTE_MAX = 5;

    private const HOUR_MAX = 30;

    public function attempt(Request $request): bool
    {
        $hash = hash('sha256', (string) $request->ip());
        $minute = 'channel-fetch:m:'.$hash;
        $hour = 'channel-fetch:h:'.$hash;

        // The check-then-hit pair must be atomic, or concurrent requests could overshoot the limits.
        return Cache::lock('channel-fetch-guard:'.$hash, 5)->block(3, function () use ($minute, $hour) {
            if (RateLimiter::tooManyAttempts($minute, self::MINUTE_MAX)
                || RateLimiter::tooManyAttempts($hour, self::HOUR_MAX)) {
                return false;
            }
            RateLimiter::hit($minute, 60);
            RateLimiter::hit($hour, 3600);

            return true;
        });
    }
}

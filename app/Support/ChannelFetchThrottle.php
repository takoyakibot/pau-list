<?php

namespace App\Support;

use Illuminate\Contracts\Cache\LockTimeoutException;
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
        try {
            return Cache::lock('channel-fetch-guard:'.$hash, 5)->block(3, function () use ($minute, $hour) {
                if (RateLimiter::tooManyAttempts($minute, self::MINUTE_MAX)
                    || RateLimiter::tooManyAttempts($hour, self::HOUR_MAX)) {
                    return false;
                }
                RateLimiter::hit($minute, 60);
                RateLimiter::hit($hour, 3600);

                return true;
            });
        } catch (LockTimeoutException) {
            return false;
        }
    }

    /** Seconds until a refused request could succeed (the longer of the exceeded windows). */
    public function retryAfter(Request $request): int
    {
        $hash = hash('sha256', (string) $request->ip());
        $wait = 1;
        foreach (['channel-fetch:m:'.$hash => self::MINUTE_MAX, 'channel-fetch:h:'.$hash => self::HOUR_MAX] as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                $wait = max($wait, RateLimiter::availableIn($key));
            }
        }

        return $wait;
    }
}

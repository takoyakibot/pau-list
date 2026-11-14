<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * Spec 8.8: rate limit refusals are counted in the log by limiter name only (no IP, key, path or proposal key).
 */
final class RateLimitLog
{
    /** Set on the request when a refusal was already logged, so the shared 429 rendering does not log it twice. */
    public const LOGGED = 'rate_limit_logged';

    public static function exceeded(string $limiter): void
    {
        Log::info('Rate limit exceeded', ['limiter' => $limiter]);
    }
}

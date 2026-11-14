<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Limits on contact form sends (spec 8.7): 3 per 10 minutes and 10 per day per client (ClientKey), 50 per day
 * overall. Hit for every send attempt (delivered or failed) and every honeypot hit; validation errors are not
 * counted. Two concurrent sends may both pass the check; the global per-client limiter bounds how far that goes.
 */
final class ContactLimits
{
    /** @return list<array{string, int, int}> key, max sends, window in seconds */
    public function limits(Request $request): array
    {
        $client = ClientKey::for($request);

        return [
            ['contact:m:'.$client, 3, 600],
            ['contact:d:'.$client, 10, 86400],
            ['contact:all', 50, 86400],
        ];
    }

    /** Seconds until the next send is allowed, or null when it is allowed now. */
    public function retryAfter(Request $request): ?int
    {
        $wait = null;
        foreach ($this->limits($request) as [$key, $max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                $wait = max($wait ?? 0, RateLimiter::availableIn($key));
            }
        }

        return $wait;
    }

    public function hit(Request $request): void
    {
        foreach ($this->limits($request) as [$key, , $window]) {
            RateLimiter::hit($key, $window);
        }
    }
}

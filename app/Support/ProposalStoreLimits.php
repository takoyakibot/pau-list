<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Limits on SUCCESSFUL proposal saves (user decision #19(1)): 5 per 10 minutes and 30 per day per client (ClientKey),
 * 500 per day overall. Checked before saving and hit only after a save succeeds, so validation errors,
 * honeypot hits and unavailable-video failures never use them up. Every POST is limited separately by
 * the `proposal-post` route limiter (20 per 10 minutes per IP).
 * Two concurrent saves may both pass the check; the POST limiter bounds how far that can go.
 */
final class ProposalStoreLimits
{
    /** @return list<array{string, int, int}> key, max saves, window in seconds */
    private function limits(Request $request): array
    {
        $ip = ClientKey::for($request);

        return [
            ['proposal-store:m:'.$ip, 5, 600],
            ['proposal-store:d:'.$ip, 30, 86400],
            ['proposal-store:all', 500, 86400],
        ];
    }

    /** Seconds until the next save is allowed, or null when it is allowed now. */
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

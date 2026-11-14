<?php

namespace App\Http\Middleware;

use App\Support\OwnerSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Web group, after the session starts (policy I3, spec 4.3): on every request an expired owner login (its access
 * token) and a stale list of the owner's unlisted videos are removed from the session, not only on owner pages.
 * Logout is skipped: it still revokes a token that has just expired (OwnerAuthController::logout).
 */
class DropExpiredOwnerData
{
    public function __construct(private readonly OwnerSession $owners) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->hasSession() && ! $request->routeIs('owner.logout')) {
            $this->owners->dropExpired();
        }

        return $next($request);
    }
}

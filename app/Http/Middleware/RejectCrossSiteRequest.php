<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * For the login start: another site must not be able to start a login in the visitor's browser,
 * which would overwrite a login the visitor has in flight (the pending state is one per session).
 * Allowed: `Sec-Fetch-Site` same-origin (the preview's link), none (typed or bookmarked), or absent
 * (browsers without Fetch Metadata). Placed before the rate limiter so rejected requests do not count.
 */
class RejectCrossSiteRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $site = $request->headers->get('Sec-Fetch-Site');
        if ($site !== null && $site !== 'same-origin' && $site !== 'none') {
            abort(400);
        }

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * For JSON endpoints that spend the YouTube read budget: only the app's own scripts may call them.
 * A third-party page must not be able to make a visitor's browser spend the budget.
 *
 * Allowed: `Sec-Fetch-Site: same-origin`, or (older browsers without Fetch Metadata)
 * no Sec-Fetch-Site header and `X-Requested-With: XMLHttpRequest`. A cross-site request
 * cannot add that custom header without a CORS preflight, which we never answer.
 * Placed before the route's rate limiter so rejected requests do not count against it.
 */
class RequireSameOriginFetch
{
    public function handle(Request $request, Closure $next): Response
    {
        $site = $request->headers->get('Sec-Fetch-Site');
        $allowed = $site === null
            ? $request->headers->get('X-Requested-With') === 'XMLHttpRequest'
            : $site === 'same-origin';

        if (! $allowed) {
            return response()->json(['message' => 'この操作はこの画面からのみ行えます'], 403);
        }

        return $next($request);
    }
}

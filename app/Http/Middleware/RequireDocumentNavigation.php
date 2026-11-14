<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * For page GETs that may spend the YouTube read budget (channel open, propose screen): a third-party
 * page may link here (top-level navigation), but must not load it as an image, script, iframe or fetch.
 * Browsers without Fetch Metadata send neither header and are allowed.
 */
class RequireDocumentNavigation
{
    public function handle(Request $request, Closure $next): Response
    {
        $mode = $request->headers->get('Sec-Fetch-Mode');
        $dest = $request->headers->get('Sec-Fetch-Dest');

        if (($mode !== null && $mode !== 'navigate') || ($dest !== null && $dest !== 'document')) {
            abort(400);
        }

        return $next($request);
    }
}

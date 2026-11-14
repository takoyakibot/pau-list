<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LimitRequestBody
{
    public const MAX_BYTES = 65536;

    public function handle(Request $request, Closure $next): Response
    {
        $length = $request->server('CONTENT_LENGTH');
        if (is_numeric($length) && (int) $length > self::MAX_BYTES) {
            throw new PostTooLargeException;
        }
        if (strlen($request->getContent()) > self::MAX_BYTES) {
            throw new PostTooLargeException;
        }

        return $next($request);
    }
}

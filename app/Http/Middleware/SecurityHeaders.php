<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public const CSP = "default-src 'self'; img-src 'self' https://i.ytimg.com https://yt3.ggpht.com; script-src 'self'; style-src 'self'; connect-src 'self'; frame-ancestors 'none'; form-action 'self' https://accounts.google.com; base-uri 'self'";

    public const INDEXABLE_ROUTES = ['home', 'privacy', 'terms'];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('Content-Security-Policy', $this->csp());
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');

        if (! in_array($request->route()?->getName(), self::INDEXABLE_ROUTES, true)) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }

    private function csp(): string
    {
        if (! app()->isLocal() || ! Vite::isRunningHot()) {
            return self::CSP;
        }

        $url = trim((string) @file_get_contents(Vite::hotFile()));
        $parts = parse_url($url);
        if (! isset($parts['scheme'], $parts['host'])) {
            return self::CSP;
        }

        $host = $parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        $origin = $parts['scheme'].'://'.$host;
        $ws = ($parts['scheme'] === 'https' ? 'wss' : 'ws').'://'.$host;

        return strtr(self::CSP, [
            "script-src 'self'" => "script-src 'self' $origin",
            // Vite dev はインライン <style> を注入するため、local の hot 時に限り style-src のみ緩める
            "style-src 'self'" => "style-src 'self' $origin 'unsafe-inline'",
            "connect-src 'self'" => "connect-src 'self' $origin $ws",
        ]);
    }
}

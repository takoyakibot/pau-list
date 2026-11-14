<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * The only place that reads the client IP (spec 8.1): rate-limit keys use an HMAC of the client's network
 * (the IPv4 address, or the /64 of an IPv6 address) so raw IPs never reach the cache, and one IPv6 user
 * cannot escape a limit by rotating addresses inside their /64.
 */
final class ClientKey
{
    public static function for(Request $request): string
    {
        return hash_hmac('sha256', self::network($request->ip()), (string) config('app.key'));
    }

    /** IPv4 as is (IPv4-mapped IPv6 included), IPv6 as its /64, anything else "unknown". */
    public static function network(?string $ip): string
    {
        if ($ip === null || $ip === '') {
            return 'unknown';
        }
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return 'unknown';
        }
        if (strlen($packed) === 4) {
            return (string) inet_ntop($packed);
        }
        if (str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            return (string) inet_ntop(substr($packed, 12));
        }

        return inet_ntop(substr($packed, 0, 8).str_repeat("\0", 8)).'/64';
    }
}

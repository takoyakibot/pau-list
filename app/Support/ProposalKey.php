<?php

namespace App\Support;

final class ProposalKey
{
    public const ROUTE_PATTERN = '[A-Za-z0-9_-]{43}';

    public static function generate(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function hash(string $key): string
    {
        return hash('sha256', $key);
    }

    public static function isWellFormed(string $key): bool
    {
        return preg_match('/^'.self::ROUTE_PATTERN.'$/D', $key) === 1;
    }
}

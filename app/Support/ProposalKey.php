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

    /**
     * The key from operator input: the 43-character key itself, or a URL containing /p/{key} (optionally followed
     * by /edit, /creation or /shared, then ?… or #…). Null otherwise.
     */
    public static function fromInput(string $input): ?string
    {
        $input = trim($input);
        if (self::isWellFormed($input)) {
            return $input;
        }
        $pattern = '#/p/('.self::ROUTE_PATTERN.')(?:/(?:edit|creation|shared))?/?(?:[?\#].*)?$#Ds';

        return preg_match($pattern, $input, $m) === 1 ? $m[1] : null;
    }
}

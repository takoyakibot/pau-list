<?php

namespace App\Support;

final class VideoUrl
{
    public const MAX_LENGTH = 200;

    public const ID_PATTERN = '/^[A-Za-z0-9_-]{11}$/D';

    private const HOSTS = ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'music.youtube.com'];

    public static function extractId(string $input): ?string
    {
        $input = trim($input);
        if ($input === '' || strlen($input) > self::MAX_LENGTH) {
            return null;
        }
        if (preg_match(self::ID_PATTERN, $input) === 1) {
            return $input;
        }

        if (preg_match('#^[A-Za-z][A-Za-z0-9+.-]*://#', $input) !== 1) {
            $input = 'https://'.$input;
        }
        $parts = parse_url($input);
        if ($parts === false
            || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['port'])) {
            return null;
        }
        $host = strtolower($parts['host'] ?? '');
        $path = $parts['path'] ?? '';

        if ($host === 'youtu.be') {
            return self::fromPath('#^/([^/]+)/?\z#', $path);
        }
        if (! in_array($host, self::HOSTS, true)) {
            return null;
        }
        if ($path === '/watch') {
            parse_str($parts['query'] ?? '', $query);
            $v = $query['v'] ?? null;

            return is_string($v) && preg_match(self::ID_PATTERN, $v) === 1 ? $v : null;
        }

        return self::fromPath('#^/(?:shorts|embed|live)/([^/]+)/?\z#', $path);
    }

    private static function fromPath(string $pattern, string $path): ?string
    {
        if (preg_match($pattern, $path, $m) === 1 && preg_match(self::ID_PATTERN, $m[1]) === 1) {
            return $m[1];
        }

        return null;
    }
}

<?php

namespace App\Support;

final class ChannelInput
{
    public const MAX_LENGTH = 200;

    public const ID_PATTERN = '/^UC[A-Za-z0-9_-]{22}$/D';

    private const HANDLE_PATTERN = '/^[\p{L}\p{N}._-]{3,30}$/Du';

    private const HOSTS = ['youtube.com', 'www.youtube.com', 'm.youtube.com'];

    public static function parse(string $input): ?ChannelRef
    {
        $input = trim($input);
        if ($input === '' || mb_strlen($input) > self::MAX_LENGTH) {
            return null;
        }

        if (preg_match(self::ID_PATTERN, $input) === 1) {
            return ChannelRef::id($input);
        }
        if ($input[0] === '@') {
            return self::handle(substr($input, 1));
        }

        return self::fromUrl($input);
    }

    private static function handle(string $handle): ?ChannelRef
    {
        return preg_match(self::HANDLE_PATTERN, $handle) === 1 ? ChannelRef::handle($handle) : null;
    }

    private static function fromUrl(string $input): ?ChannelRef
    {
        if (preg_match('#^[A-Za-z][A-Za-z0-9+.-]*://#', $input) !== 1) {
            $input = 'https://'.$input;
        }
        $parts = parse_url($input);
        if ($parts === false
            || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || ! in_array(strtolower($parts['host'] ?? ''), self::HOSTS, true)
            || isset($parts['user']) || isset($parts['port'])) {
            return null;
        }

        $path = rawurldecode($parts['path'] ?? '');
        if (preg_match('#^/channel/(UC[A-Za-z0-9_-]{22})(?:/|\z)#', $path, $m) === 1) {
            return ChannelRef::id($m[1]);
        }
        if (preg_match('#^/@([^/]+)(?:/|\z)#u', $path, $m) === 1) {
            return self::handle($m[1]);
        }

        return null;
    }
}

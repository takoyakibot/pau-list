<?php

namespace App\Support;

/** RFC 7636 PKCE: code_verifier generation and the S256 code_challenge. */
final class Pkce
{
    public static function verifier(): string
    {
        return self::base64url(random_bytes(32));
    }

    public static function challenge(#[\SensitiveParameter] string $verifier): string
    {
        return self::base64url(hash('sha256', $verifier, true));
    }

    public static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}

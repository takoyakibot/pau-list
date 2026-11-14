<?php

namespace App\Services\YouTube;

use RuntimeException;

/** A failed token request. The message carries only the fixed reason code, never response bodies or secrets. */
final class OAuthFailed extends RuntimeException
{
    public const CONNECTION = 'connection';

    public const REDIRECT = 'redirect';

    public const HTTP_ERROR = 'http_error';

    public const MALFORMED = 'malformed';

    public function __construct(public readonly string $reason)
    {
        parent::__construct('Google OAuth token request failed: '.$reason);
    }
}

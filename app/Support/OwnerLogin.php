<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/** A logged-in channel owner. The access token never leaves the session. */
final readonly class OwnerLogin
{
    public function __construct(
        #[\SensitiveParameter] public string $accessToken,
        public CarbonImmutable $expiresAt,
        public string $channelId,
        public string $channelTitle,
    ) {}

    public function isExpired(): bool
    {
        return now()->greaterThanOrEqualTo($this->expiresAt);
    }
}

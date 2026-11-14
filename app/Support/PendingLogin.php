<?php

namespace App\Support;

/** An OAuth login in progress: lives only in the (encrypted) session until the callback pulls it. */
final readonly class PendingLogin
{
    public function __construct(
        #[\SensitiveParameter] public string $state,
        #[\SensitiveParameter] public string $verifier,
        #[\SensitiveParameter] public string $proposalKey,
        public int $issuedAt,
    ) {}

    public function isExpired(): bool
    {
        return now()->getTimestamp() - $this->issuedAt > OwnerSession::STATE_TTL_SECONDS;
    }
}

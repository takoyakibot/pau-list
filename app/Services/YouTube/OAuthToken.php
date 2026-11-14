<?php

namespace App\Services\YouTube;

use Carbon\CarbonImmutable;

/** A validated access token. refresh_token / id_token are deliberately not represented (spec 6.7 E4). */
final readonly class OAuthToken
{
    /** @param list<string> $scopes */
    public function __construct(
        #[\SensitiveParameter] public string $accessToken,
        public CarbonImmutable $expiresAt,
        public array $scopes,
    ) {}

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }
}

<?php

namespace App\Services;

use App\Models\Proposal;

final readonly class CreatedProposal
{
    /** $key is the raw public key: show it once, never store or log it. */
    public function __construct(
        public Proposal $proposal,
        public string $key,
    ) {}
}

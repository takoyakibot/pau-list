<?php

namespace App\Services\Creation;

use App\Models\PlaylistCreation;

/** $created is false when the proposal already had a creation (nothing new was read, created or reserved). */
final readonly class StartedCreation
{
    public function __construct(
        public PlaylistCreation $creation,
        public bool $created,
    ) {}
}

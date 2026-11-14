<?php

namespace App\Services\YouTube;

final readonly class OwnerPlaylistsPage
{
    /** @param list<OwnerPlaylist> $items */
    public function __construct(
        public array $items,
        public bool $hasMore,
    ) {}
}

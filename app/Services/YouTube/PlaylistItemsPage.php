<?php

namespace App\Services\YouTube;

final readonly class PlaylistItemsPage
{
    /** @param list<PlaylistItem> $items */
    public function __construct(
        public array $items,
        public ?string $nextPageToken,
    ) {}
}

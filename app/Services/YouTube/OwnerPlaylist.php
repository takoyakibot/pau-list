<?php

namespace App\Services\YouTube;

use Carbon\CarbonImmutable;

/** One of the owner's own playlists (playlists.list?mine=true), used to reconcile an uncertain playlists.insert. */
final readonly class OwnerPlaylist
{
    public function __construct(
        public string $id,
        public string $title,
        public ?CarbonImmutable $publishedAt,
    ) {}
}

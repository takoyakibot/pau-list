<?php

namespace App\Services\YouTube;

use Carbon\CarbonImmutable;

final readonly class PlaylistItem
{
    public function __construct(
        public string $videoId,
        public string $title,
        public ?string $thumbnailUrl,
        public ?CarbonImmutable $publishedAt,
        public string $privacyStatus,
    ) {}
}

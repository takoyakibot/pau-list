<?php

namespace App\Services\YouTube;

final readonly class ChannelInfo
{
    public function __construct(
        public string $id,
        public string $title,
        public ?string $thumbnailUrl,
        public string $uploadsPlaylistId,
        public ?string $handle,
    ) {}
}

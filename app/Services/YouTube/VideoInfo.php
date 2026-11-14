<?php

namespace App\Services\YouTube;

use Carbon\CarbonImmutable;

final readonly class VideoInfo
{
    public function __construct(
        public string $id,
        public string $title,
        public ?string $thumbnailUrl,
        public string $channelId,
        public string $channelTitle,
        public ?int $durationSeconds,
        public ?CarbonImmutable $publishedAt,
        public string $privacyStatus,
    ) {}

    public function isPublic(): bool
    {
        return $this->privacyStatus === 'public';
    }
}

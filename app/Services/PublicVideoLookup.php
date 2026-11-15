<?php

namespace App\Services;

use App\Services\YouTube\ReadBudgetExceeded;
use App\Services\YouTube\YouTubeApiException;
use App\Services\YouTube\YouTubeReader;
use Illuminate\Support\Facades\Cache;

/**
 * Adding one video by URL: a public video looked up with the API key (read budget), shared by everyone for
 * 10 minutes under `video-lookup:{id}`. Only public videos (or "not found") ever go into that shared key:
 * an owner's unlisted video is looked up separately with the owner's token (OwnerEditController::lookup)
 * and is never cached here.
 */
class PublicVideoLookup
{
    public function __construct(private readonly YouTubeReader $reader) {}

    public static function cacheKey(string $videoId): string
    {
        return "video-lookup:{$videoId}";
    }

    /**
     * Null when the video does not exist or is not public (the API key cannot tell deleted, private and
     * unlisted apart). Not-found results are cached too; budget/transport failures throw and are not.
     *
     * @return array{id: string, title: string, thumbnail_url: ?string, channel_id: string, channel_title: string, published_at: ?string}|null
     *
     * @throws ReadBudgetExceeded
     * @throws YouTubeApiException
     */
    public function find(string $videoId): ?array
    {
        $hit = Cache::remember(self::cacheKey($videoId), 600, function () use ($videoId) {
            $info = $this->reader->videos([$videoId])[$videoId] ?? null;
            if ($info === null || ! $info->isPublic()) {
                return ['found' => false];
            }

            return ['found' => true, 'video' => [
                'id' => $info->id,
                'title' => $info->title,
                'thumbnail_url' => $info->thumbnailUrl,
                'channel_id' => $info->channelId,
                'channel_title' => $info->channelTitle,
                'published_at' => $info->publishedAt?->utc()->format('Y-m-d\TH:i:s\Z'),
            ]];
        });

        return $hit['found'] ? $hit['video'] : null;
    }
}

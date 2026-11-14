<?php

namespace App\Support;

use InvalidArgumentException;

final class QuotaPolicy
{
    public const PLAYLIST_UNITS = 50;

    public const ITEM_UNITS = 50;

    public static function maxItemsPerPlaylist(): int
    {
        return self::itemsAffordable((int) config('youtube.quota.write_limit'));
    }

    public static function itemsAffordable(int $freeUnits): int
    {
        return max(0, intdiv($freeUnits - self::PLAYLIST_UNITS, self::ITEM_UNITS));
    }

    /**
     * owner_read has no cap of its own but shares Google's daily project quota with writes and reads.
     * Optional owner reads (the unlisted list) stop once today's owner_read reaches half of the headroom
     * left after the write limit and the read budget.
     */
    public static function ownerReadGuard(): int
    {
        $headroom = (int) config('youtube.quota.google_daily')
            - (int) config('youtube.quota.write_limit')
            - (int) config('youtube.quota.read_budget');

        return intdiv(max(0, $headroom), 2);
    }

    public static function validateConfig(): void
    {
        $write = (int) config('youtube.quota.write_limit');
        $read = (int) config('youtube.quota.read_budget');
        $daily = (int) config('youtube.quota.google_daily');

        if ($write < self::PLAYLIST_UNITS + self::ITEM_UNITS) {
            throw new InvalidArgumentException('QUOTA_WRITE_LIMIT must be at least 100.');
        }
        if ($read < 0) {
            throw new InvalidArgumentException('QUOTA_READ_BUDGET must not be negative.');
        }
        if ($write + $read > $daily) {
            throw new InvalidArgumentException('QUOTA_WRITE_LIMIT + QUOTA_READ_BUDGET must not exceed QUOTA_GOOGLE_DAILY.');
        }
    }
}

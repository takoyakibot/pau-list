<?php

namespace App\Support;

use Carbon\CarbonImmutable;

final class QuotaClock
{
    public const TIMEZONE = 'America/Los_Angeles';

    public const DISPLAY_TIMEZONE = 'Asia/Tokyo';

    public static function todayPt(): string
    {
        return CarbonImmutable::now(self::TIMEZONE)->format('Y-m-d');
    }

    public static function nextResetJst(): CarbonImmutable
    {
        return CarbonImmutable::now(self::TIMEZONE)
            ->startOfDay()
            ->addDay()
            ->setTimezone(self::DISPLAY_TIMEZONE);
    }
}

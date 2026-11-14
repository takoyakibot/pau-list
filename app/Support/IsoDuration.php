<?php

namespace App\Support;

final class IsoDuration
{
    public static function toSeconds(?string $iso): ?int
    {
        if ($iso === null || $iso === ''
            || preg_match('/^P(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/D', $iso, $m) !== 1
            || $iso === 'P' || str_ends_with($iso, 'T')) {
            return null;
        }

        return ((int) ($m[1] ?? 0)) * 86400 + ((int) ($m[2] ?? 0)) * 3600
            + ((int) ($m[3] ?? 0)) * 60 + (int) ($m[4] ?? 0);
    }
}

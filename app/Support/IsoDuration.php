<?php

namespace App\Support;

final class IsoDuration
{
    public static function toSeconds(?string $iso): ?int
    {
        if ($iso === null || $iso === ''
            || preg_match('/^P(?:(\d{1,9})D)?(?:T(?:(\d{1,9})H)?(?:(\d{1,9})M)?(?:(\d{1,9})S)?)?$/D', $iso, $m) !== 1
            || $iso === 'P' || str_ends_with($iso, 'T')) {
            return null;
        }

        return ((int) ($m[1] ?? 0)) * 86400 + ((int) ($m[2] ?? 0)) * 3600
            + ((int) ($m[3] ?? 0)) * 60 + (int) ($m[4] ?? 0);
    }
}

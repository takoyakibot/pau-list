<?php

namespace App\Support;

final class DurationFormat
{
    /** "1:05" / "1:02:03"; unknown duration → "". */
    public static function clock(?int $s): string
    {
        if ($s === null) {
            return '';
        }
        $s = max(0, $s);
        $h = intdiv($s, 3600);
        $m = intdiv($s % 3600, 60);
        $sec = $s % 60;

        return $h > 0
            ? sprintf('%d:%02d:%02d', $h, $m, $sec)
            : sprintf('%d:%02d', $m, $sec);
    }

    /** "1 時間 2 分" / "1 分" / "1 分未満" (seconds are truncated). */
    public static function total(int $s): string
    {
        $s = max(0, $s);
        $h = intdiv($s, 3600);
        $m = intdiv($s % 3600, 60);

        if ($h === 0) {
            return $m === 0 ? '1 分未満' : "{$m} 分";
        }

        return $m === 0 ? "{$h} 時間" : "{$h} 時間 {$m} 分";
    }
}

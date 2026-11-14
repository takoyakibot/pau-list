<?php

namespace App\Services\Creation;

use Carbon\CarbonImmutable;
use RuntimeException;

/** Paused by Google's quotaExceeded on this PT day: resuming before the reset would only fail again (spec 7.4). */
class GoogleQuotaNotReset extends RuntimeException
{
    public function __construct(public readonly CarbonImmutable $resetAtJst)
    {
        parent::__construct('Google quota has not been reset yet.');
    }

    public function sentence(): string
    {
        return 'YouTube 側の本日の上限に達しているため、まだ再開できません。次にリセットされるのは 日本時間 '.$this->resetAtJst->format('n月j日 G時').' です';
    }
}

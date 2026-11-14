<?php

namespace App\Support;

use Carbon\CarbonImmutable;

final class RemainingToday
{
    public function __construct(
        public readonly int $items,
        public readonly CarbonImmutable $resetAtJst,
    ) {}

    public function exhausted(): bool
    {
        return $this->items < 1;
    }

    public function covers(int $n): bool
    {
        return $n <= $this->items;
    }
}

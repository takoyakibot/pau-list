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

    /** The spec 5.5 sentence: the items still affordable today, or the next reset time when none. */
    public function sentence(): string
    {
        return $this->exhausted()
            ? '本日はこれ以上作成できません。次にリセットされるのは 日本時間 '.$this->resetLabel().' です'
            : '本日の残り: 再生リスト 1 つ＋動画あと '.$this->items.' 本まで作成できます';
    }

    /** sentence(), plus how many of $n videos to drop (or to wait for the reset) when some are still affordable. */
    public function shortageSentence(int $n): string
    {
        $sentence = $this->sentence();
        if (! $this->exhausted() && $n > $this->items) {
            $sentence .= '。あと '.($n - $this->items).' 本減らすか、リセット後に作成してください（次にリセットされるのは 日本時間 '.$this->resetLabel().' です）';
        }

        return $sentence;
    }

    public function resetLabel(): string
    {
        return $this->resetAtJst->format('n月j日 G時');
    }
}

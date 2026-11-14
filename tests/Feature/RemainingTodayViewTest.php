<?php

namespace Tests\Feature;

use App\Support\RemainingToday;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class RemainingTodayViewTest extends TestCase
{
    private function reset(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-11-15 17:00', 'Asia/Tokyo');
    }

    public function test_shows_items_left(): void
    {
        $html = view('partials.remaining-today', ['remaining' => new RemainingToday(12, $this->reset()), 'itemCount' => null, 'note' => null])->render();
        $this->assertStringContainsString('本日の残り: 再生リスト 1 つ＋動画あと 12 本まで作成できます', $html);
    }

    public function test_shows_reset_time_when_exhausted(): void
    {
        $html = view('partials.remaining-today', ['remaining' => new RemainingToday(0, $this->reset()), 'itemCount' => null, 'note' => null])->render();
        $this->assertStringContainsString('本日はこれ以上作成できません。次にリセットされるのは 日本時間 11月15日 17時 です', $html);
    }

    public function test_shows_reset_time_when_item_count_exceeds_remaining(): void
    {
        $html = view('partials.remaining-today', ['remaining' => new RemainingToday(2, $this->reset()), 'itemCount' => 3, 'note' => null])->render();
        $this->assertStringContainsString('この提案（動画 3 本）は本日の残りを超えています。次にリセットされるのは 日本時間 11月15日 17時 です', $html);
    }

    public function test_max_items_partial_uses_policy(): void
    {
        config(['youtube.quota.write_limit' => 1000]);
        $this->assertStringContainsString('1 つの再生リストに入れられるのは最大 19 本', view('partials.max-items')->render());
    }
}

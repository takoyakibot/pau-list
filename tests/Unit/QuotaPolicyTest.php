<?php

namespace Tests\Unit;

use App\Support\QuotaPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class QuotaPolicyTest extends TestCase
{
    public static function limits(): array
    {
        return [[5000, 99], [1000, 19], [100, 1]];
    }

    #[DataProvider('limits')]
    public function test_max_items_is_derived_from_write_limit(int $limit, int $expected): void
    {
        config(['youtube.quota.write_limit' => $limit]);
        $this->assertSame($expected, QuotaPolicy::maxItemsPerPlaylist());
    }

    public function test_items_affordable(): void
    {
        $this->assertSame(1, QuotaPolicy::itemsAffordable(149));
        $this->assertSame(0, QuotaPolicy::itemsAffordable(99));
        $this->assertSame(0, QuotaPolicy::itemsAffordable(0));
        $this->assertSame(0, QuotaPolicy::itemsAffordable(-50));
        $this->assertSame(99, QuotaPolicy::itemsAffordable(5000));
    }

    public function test_validate_rejects_write_limit_below_100(): void
    {
        config(['youtube.quota.write_limit' => 99]);
        $this->expectException(InvalidArgumentException::class);
        QuotaPolicy::validateConfig();
    }

    public function test_validate_rejects_limits_exceeding_google_daily(): void
    {
        config(['youtube.quota.write_limit' => 9000, 'youtube.quota.read_budget' => 2000, 'youtube.quota.google_daily' => 10000]);
        $this->expectException(InvalidArgumentException::class);
        QuotaPolicy::validateConfig();
    }

    public function test_default_config_is_valid(): void
    {
        QuotaPolicy::validateConfig();
        $this->addToAssertionCount(1);
    }
}

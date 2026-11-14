<?php

namespace Tests\Feature\MySql;

use App\Models\QuotaReservation;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\CreatesProposals;
use Tests\Support\RunsMySqlWorkers;
use Tests\TestCase;

/**
 * MySQL only: five processes reserving at once must never reserve more than the write limit (spec 5.4).
 * The children use their own connections, so the rows are committed (truncation instead of a transaction).
 */
#[Group('mysql')]
class MySqlConcurrentReservationTest extends TestCase
{
    use CreatesProposals;
    use DatabaseTruncation {
        truncateDatabaseTables as private truncateAllTables;
    }
    use RunsMySqlWorkers;

    /** @var list<string> */
    protected $exceptTables = ['migrations'];

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL only.');
        }
    }

    /** SQLite (in-memory) runs nothing here: the tests are skipped and must not migrate a throwaway database. */
    protected function truncateDatabaseTables(): void
    {
        if (DB::getDriverName() === 'mysql') {
            $this->truncateAllTables();
        }
    }

    public function test_concurrent_reservations_never_exceed_the_limit(): void
    {
        config(['youtube.quota.write_limit' => 5000]);
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = $this->makeCreation(1)->id;
        }

        try {
            $outputs = $this->runWorkers('tests/Support/mysql/reserve-worker.php', array_map(fn (int $id) => [(string) $id, '2000'], $ids));

            $this->assertSame(2, count(array_filter($outputs, fn ($o) => $o === 'ok')), 'outputs: '.json_encode($outputs));
            $this->assertSame(3, count(array_filter($outputs, fn ($o) => $o === 'no')), 'outputs: '.json_encode($outputs));
            $reserved = (int) QuotaReservation::where('status', QuotaReservation::ACTIVE)->sum('reserved_units');
            $this->assertSame(4000, $reserved);
            $this->assertLessThanOrEqual(5000, $reserved);
        } finally {
            // The rows were committed: leave nothing behind for later RefreshDatabase tests.
            $this->truncateAllTables();
        }
    }
}

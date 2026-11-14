<?php

namespace Tests\Feature\MySql;

use App\Models\QuotaReservation;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\CreatesProposals;
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
            $outputs = $this->runWorkers($ids, 2000);

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

    /**
     * @param  list<int>  $creationIds
     * @return list<string> trimmed stdout of each worker
     */
    private function runWorkers(array $creationIds, int $units): array
    {
        $db = config('database.connections.mysql');
        $env = [
            'APP_ENV' => 'testing',
            'APP_KEY' => (string) config('app.key'),
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => (string) $db['host'],
            'DB_PORT' => (string) $db['port'],
            'DB_DATABASE' => (string) $db['database'],
            'DB_USERNAME' => (string) $db['username'],
            'DB_PASSWORD' => (string) $db['password'],
            'DB_URL' => '',
            'QUOTA_WRITE_LIMIT' => '5000',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
        ];
        $script = base_path('tests/Support/mysql/reserve-worker.php');

        $procs = [];
        foreach ($creationIds as $id) {
            $pipes = [];
            // stderr goes to a file: an unread stderr pipe could fill up and block the child.
            $errFile = tempnam(sys_get_temp_dir(), 'reserve-worker');
            $proc = proc_open(
                [PHP_BINARY, $script, (string) $id, (string) $units],
                [1 => ['pipe', 'w'], 2 => ['file', $errFile, 'w']],
                $pipes,
                base_path(),
                $env,
            );
            $this->assertIsResource($proc);
            $procs[] = [$proc, $pipes, $errFile];
        }

        $outputs = [];
        foreach ($procs as [$proc, $pipes, $errFile]) {
            $out = trim((string) stream_get_contents($pipes[1]));
            fclose($pipes[1]);
            $code = proc_close($proc);
            $err = trim((string) file_get_contents($errFile));
            unlink($errFile);
            $this->assertSame(0, $code, 'worker failed: '.$err);
            $outputs[] = $out;
        }

        return $outputs;
    }
}

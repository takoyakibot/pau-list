<?php

namespace Tests\Feature\MySql;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * MySQL only: SELECT … FOR UPDATE must block a second connection. Rows have to be committed to be
 * visible from the other connection, so this class truncates instead of wrapping in a transaction.
 */
#[Group('mysql')]
class MySqlLockingTest extends TestCase
{
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

    public function test_for_update_blocks_a_second_connection(): void
    {
        config(['database.connections.mysql_second' => config('database.connections.mysql')]);
        DB::table('quota_usage')->insert(['day_pt' => '2026-11-15', 'bucket' => 'write', 'units' => 0]);
        $second = DB::connection('mysql_second');

        DB::beginTransaction();
        try {
            $this->assertNotNull(DB::table('quota_usage')->where('bucket', 'write')->lockForUpdate()->first());

            $second->statement('SET SESSION innodb_lock_wait_timeout = 1');
            try {
                $second->table('quota_usage')->where('bucket', 'write')->lockForUpdate()->first();
                $this->fail('The second connection was not blocked.');
            } catch (QueryException $e) {
                $this->assertSame(1205, $e->errorInfo[1] ?? null);
            }
        } finally {
            DB::rollBack();
            DB::purge('mysql_second');
            // The row was committed: leave nothing behind for later RefreshDatabase tests.
            DB::table('quota_usage')->delete();
        }
    }
}

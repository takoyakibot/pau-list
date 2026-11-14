<?php

namespace Tests\Support;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PDOException;

/**
 * Makes every statement against one table fail with a QueryException carrying the given SQLSTATE.
 * Unlike dropping the table (DDL), this works the same on SQLite and MySQL and does not trigger
 * MySQL's implicit commit, so the RefreshDatabase transaction stays intact.
 */
trait FailsQueries
{
    protected function failQueriesOn(string $table, string $sqlstate = '42S02'): void
    {
        $pattern = '/\b(from|into|update|table)\s+["`]?'.preg_quote($table, '/').'["`]?/i';

        DB::connection()->beforeExecuting(function (string $sql, array $bindings, Connection $connection) use ($pattern, $sqlstate) {
            if (preg_match($pattern, $sql) !== 1) {
                return;
            }

            $pdo = new class("SQLSTATE[{$sqlstate}]: simulated failure", $sqlstate) extends PDOException
            {
                public function __construct(string $message, string $sqlstate)
                {
                    parent::__construct($message);
                    $this->code = $sqlstate;
                }
            };

            throw new QueryException($connection->getName(), $sql, $bindings, $pdo);
        });
    }
}

<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Http::preventStrayRequests();
    }

    /**
     * Runs before RefreshDatabase / DatabaseTruncation: a MySQL (mysql group) run migrates fresh and truncates,
     * so it only ever touches a database whose name ends with `_test` (CI: listmaker_test). Anything else is
     * skipped before a single table is touched.
     */
    protected function setUpTraits()
    {
        $connection = DB::connection();
        if (! self::isSafeTestDatabase($connection->getDriverName(), (string) $connection->getDatabaseName())) {
            $this->markTestSkipped('MySQL tests run only against a database whose name ends with _test.');
        }

        return parent::setUpTraits();
    }

    public static function isSafeTestDatabase(string $driver, string $database): bool
    {
        return ! in_array($driver, ['mysql', 'mariadb'], true) || str_ends_with($database, '_test');
    }
}

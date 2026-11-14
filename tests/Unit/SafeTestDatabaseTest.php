<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\TestCase as AppTestCase;

/** The MySQL guard of Tests\TestCase: only a database named *_test is migrated/truncated by the test run. */
class SafeTestDatabaseTest extends TestCase
{
    public function test_mysql_and_mariadb_need_a_test_suffix(): void
    {
        $this->assertTrue(AppTestCase::isSafeTestDatabase('mysql', 'listmaker_test'));
        $this->assertTrue(AppTestCase::isSafeTestDatabase('mariadb', 'x_test'));
        $this->assertFalse(AppTestCase::isSafeTestDatabase('mysql', 'listmaker'));
        $this->assertFalse(AppTestCase::isSafeTestDatabase('mysql', 'listmaker_test_copy'));
        $this->assertFalse(AppTestCase::isSafeTestDatabase('mariadb', ''));
    }

    public function test_sqlite_is_always_allowed(): void
    {
        $this->assertTrue(AppTestCase::isSafeTestDatabase('sqlite', ':memory:'));
    }
}

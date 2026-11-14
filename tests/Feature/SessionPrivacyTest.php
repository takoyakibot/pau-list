<?php

namespace Tests\Feature;

use App\Session\PrivacyDatabaseSessionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SessionPrivacyTest extends TestCase
{
    use RefreshDatabase;

    public function test_sessions_table_has_no_ip_or_user_agent_columns(): void
    {
        $this->assertFalse(Schema::hasColumn('sessions', 'ip_address'));
        $this->assertFalse(Schema::hasColumn('sessions', 'user_agent'));
        foreach (['id', 'user_id', 'payload', 'last_activity'] as $column) {
            $this->assertTrue(Schema::hasColumn('sessions', $column), $column);
        }
    }

    public function test_database_driver_stores_no_request_information(): void
    {
        config(['session.driver' => 'database']);
        app('session')->forgetDrivers();
        $this->assertInstanceOf(PrivacyDatabaseSessionHandler::class, app('session')->driver('database')->getHandler());

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.77', 'HTTP_USER_AGENT' => 'SecretAgent/1.0'])
            ->get('/')->assertOk();

        $row = DB::table('sessions')->sole();
        $this->assertSame(['id', 'last_activity', 'payload', 'user_id'], collect(array_keys((array) $row))->sort()->values()->all());
        $this->assertStringNotContainsString('203.0.113.77', json_encode($row));
        $this->assertStringNotContainsString('SecretAgent', json_encode($row));
    }
}

<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DatabaseSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_session_and_cache_tables_exist(): void
    {
        $this->assertTrue(Schema::hasTable('sessions'));
        $this->assertTrue(Schema::hasTable('cache'));
        $this->assertTrue(Schema::hasTable('cache_locks'));
        foreach (['users', 'password_reset_tokens', 'jobs', 'job_batches', 'failed_jobs'] as $t) {
            $this->assertFalse(Schema::hasTable($t), "{$t} must not exist");
        }
    }
}

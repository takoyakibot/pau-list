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

    public function test_expiration_record_holds_no_api_data(): void
    {
        $this->assertSame(['channel_id', 'expired_at'], Schema::getColumnListing('channel_cache_expirations'));
    }

    public function test_channel_cache_tables_have_spec_columns(): void
    {
        $this->assertSame(
            ['channel_id', 'handle', 'title', 'thumbnail_url', 'uploads_playlist_id', 'meta_fetched_at', 'cached_since', 'last_synced_at', 'truncated', 'expires_at'],
            Schema::getColumnListing('channel_caches'),
        );
        $this->assertSame(
            ['channel_id', 'video_id', 'title', 'thumbnail_url', 'published_at', 'fetched_at'],
            Schema::getColumnListing('channel_cache_videos'),
        );
    }
}

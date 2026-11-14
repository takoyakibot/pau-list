<?php

namespace Tests\Feature;

use App\Models\Proposal;
use App\Support\ProposalKey;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_creation_tables_have_spec_columns(): void
    {
        $this->assertSame(['id', 'proposal_id', 'owner_channel_id', 'title', 'description', 'privacy_status', 'status', 'pause_reason', 'youtube_playlist_id', 'playlist_attempted_at', 'playlist_leased_at', 'playlist_attempts', 'estimated_units', 'started_at', 'completed_at', 'updated_at', 'paused_day_pt', 'reconcile_failures'], Schema::getColumnListing('playlist_creations'));
        $this->assertSame(['id', 'creation_id', 'position', 'video_id', 'title', 'thumbnail_url', 'is_outside_channel', 'status', 'attempts', 'leased_at', 'last_error', 'youtube_item_id'], Schema::getColumnListing('playlist_creation_items'));
        $this->assertSame(['id', 'creation_id', 'day_pt', 'reserved_units', 'consumed_units', 'status', 'created_at', 'updated_at'], Schema::getColumnListing('quota_reservations'));
    }

    public function test_creation_items_have_no_standalone_status_index(): void
    {
        // Range updates of one creation's items (creation_id, status) must not lock other creations' rows through
        // a status-only index (final review, race 1).
        $columns = array_map(fn (array $i) => $i['columns'], Schema::getIndexes('playlist_creation_items'));
        $this->assertNotContains(['status'], $columns);
        $this->assertContains(['creation_id', 'position'], $columns);
    }

    public function test_one_creation_per_proposal(): void
    {
        $proposal = Proposal::create([
            'key_hash' => ProposalKey::hash(ProposalKey::generate()),
            'channel_id' => 'UC'.str_repeat('a', 22),
            'channel_title' => 'c',
            'title' => 't',
            'description' => '',
            'item_count' => 1,
            'created_at' => now(),
            'expires_at' => now()->addDays(30),
        ]);
        $row = [
            'proposal_id' => $proposal->id,
            'owner_channel_id' => 'UC'.str_repeat('a', 22),
            'title' => 't',
            'description' => '',
            'privacy_status' => 'private',
            'status' => 'pending',
            'estimated_units' => 100,
            'started_at' => now(),
            'updated_at' => now(),
        ];
        DB::table('playlist_creations')->insert($row);

        $this->expectException(QueryException::class);
        DB::table('playlist_creations')->insert($row);
    }
}

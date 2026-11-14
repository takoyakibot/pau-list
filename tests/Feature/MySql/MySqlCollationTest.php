<?php

namespace Tests\Feature\MySql;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/** MySQL only: YouTube IDs are case-sensitive, so their columns must compare in binary (utf8mb4_bin). */
#[Group('mysql')]
class MySqlCollationTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, list<string>> table => YouTube ID columns */
    private const ID_COLUMNS = [
        'channel_caches' => ['channel_id', 'uploads_playlist_id'],
        'channel_cache_videos' => ['channel_id', 'video_id'],
        'channel_cache_expirations' => ['channel_id'],
        'proposals' => ['channel_id'],
        'proposal_items' => ['video_id', 'video_channel_id'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL only.');
        }
    }

    public function test_youtube_id_columns_are_utf8mb4_bin(): void
    {
        foreach (self::ID_COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                $collation = DB::table('information_schema.COLUMNS')
                    ->whereRaw('TABLE_SCHEMA = DATABASE()')
                    ->where('TABLE_NAME', $table)
                    ->where('COLUMN_NAME', $column)
                    ->value('COLLATION_NAME');
                $this->assertSame('utf8mb4_bin', $collation, "{$table}.{$column}");
            }
        }
    }

    public function test_ids_differing_only_in_case_are_distinct(): void
    {
        $lower = 'UC'.str_repeat('a', 22);
        $upper = 'UC'.str_repeat('A', 22);
        foreach ([$lower, $upper] as $id) {
            DB::table('channel_caches')->insert([
                'channel_id' => $id,
                'handle' => null,
                'title' => 'ch',
                'thumbnail_url' => null,
                'uploads_playlist_id' => 'UU'.substr($id, 2),
                'meta_fetched_at' => now(),
                'cached_since' => now(),
                'last_synced_at' => now(),
                'truncated' => false,
                'expires_at' => now()->addDays(30),
            ]);
        }

        $this->assertSame([$upper], DB::table('channel_caches')->where('channel_id', $upper)->pluck('channel_id')->all());
    }
}

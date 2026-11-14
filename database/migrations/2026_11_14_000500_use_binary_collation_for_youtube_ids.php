<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * YouTube channel/video/playlist IDs are case-sensitive, but MySQL/MariaDB's default
 * utf8mb4_unicode_ci compares them case-insensitively (a lookup for "UCAAA…" would find "UCaaa…",
 * and a unique key would reject two IDs differing only in case). Switch every ID column to
 * utf8mb4_bin. SQLite already compares with BINARY, so this is a no-op there.
 * `handle` is deliberately left case-insensitive (it is stored lowercased).
 */
return new class extends Migration
{
    /** @var array<string, array<string, string>> table => column => column type (NOT NULL everywhere) */
    private const COLUMNS = [
        'channel_caches' => ['channel_id' => 'VARCHAR(24)', 'uploads_playlist_id' => 'VARCHAR(255)'],
        'channel_cache_videos' => ['channel_id' => 'VARCHAR(24)', 'video_id' => 'CHAR(11)'],
        'channel_cache_expirations' => ['channel_id' => 'VARCHAR(24)'],
        'proposals' => ['channel_id' => 'VARCHAR(24)'],
        'proposal_items' => ['video_id' => 'CHAR(11)', 'video_channel_id' => 'VARCHAR(24)'],
    ];

    private const FOREIGN_KEY = 'channel_cache_videos_channel_id_foreign';

    public function up(): void
    {
        $this->convert('utf8mb4_bin');
    }

    public function down(): void
    {
        $this->convert((string) config('database.connections.'.DB::getDefaultConnection().'.collation', 'utf8mb4_unicode_ci'));
    }

    private function convert(string $collation): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }
        if (preg_match('/^[A-Za-z0-9_]+$/D', $collation) !== 1) {
            throw new InvalidArgumentException('Invalid collation name.');
        }

        // Both sides of a foreign key must share a collation: drop it, convert both, re-add it.
        Schema::table('channel_cache_videos', fn ($table) => $table->dropForeign(self::FOREIGN_KEY));

        foreach (self::COLUMNS as $table => $columns) {
            $parts = [];
            foreach ($columns as $column => $type) {
                $parts[] = "MODIFY `{$column}` {$type} CHARACTER SET utf8mb4 COLLATE {$collation} NOT NULL";
            }
            DB::statement("ALTER TABLE `{$table}` ".implode(', ', $parts));
        }

        Schema::table('channel_cache_videos', fn ($table) => $table->foreign('channel_id', self::FOREIGN_KEY)
            ->references('channel_id')->on('channel_caches')->cascadeOnDelete());
    }
};

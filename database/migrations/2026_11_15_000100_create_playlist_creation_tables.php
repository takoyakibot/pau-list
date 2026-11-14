<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Creation snapshot (spec 4.2) and write-quota reservations (5.4). Tokens are never stored here (7.7).
 * Added by the Phase 3 plan: playlist_leased_at / playlist_attempts (lease and send count of playlists.insert).
 */
return new class extends Migration
{
    /**
     * YouTube ID columns compared in binary on MySQL/MariaDB (see 2026_11_14_000500).
     *
     * @var array<string, array<string, string>> table => column => column type with nullability
     */
    private const ID_COLUMNS = [
        'playlist_creations' => ['owner_channel_id' => 'VARCHAR(24) %s NOT NULL', 'youtube_playlist_id' => 'VARCHAR(64) %s NULL'],
        'playlist_creation_items' => ['video_id' => 'CHAR(11) %s NOT NULL', 'youtube_item_id' => 'VARCHAR(128) %s NULL'],
    ];

    public function up(): void
    {
        Schema::create('playlist_creations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proposal_id')->unique()->constrained('proposals')->cascadeOnDelete();
            $table->string('owner_channel_id', 24);
            $table->string('title', 150);
            $table->text('description');
            $table->enum('privacy_status', ['private', 'unlisted', 'public']);
            $table->enum('status', ['pending', 'creating_playlist', 'adding', 'paused', 'completed', 'needs_check']);
            $table->string('pause_reason', 20)->nullable();
            $table->string('youtube_playlist_id', 64)->nullable();
            $table->dateTime('playlist_attempted_at')->nullable();
            $table->dateTime('playlist_leased_at')->nullable();
            $table->unsignedTinyInteger('playlist_attempts')->default(0);
            $table->unsignedInteger('estimated_units');
            $table->dateTime('started_at');
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('updated_at');
        });

        Schema::create('playlist_creation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('creation_id')->constrained('playlist_creations')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->char('video_id', 11);
            $table->string('title');
            $table->string('thumbnail_url')->nullable();
            $table->boolean('is_outside_channel');
            $table->enum('status', ['pending', 'in_flight', 'done', 'failed', 'skipped'])->index();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->dateTime('leased_at')->nullable();
            $table->string('last_error', 64)->nullable();
            $table->string('youtube_item_id', 128)->nullable();
            $table->unique(['creation_id', 'position']);
            $table->unique(['creation_id', 'video_id']);
        });

        Schema::create('quota_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('creation_id')->constrained('playlist_creations')->cascadeOnDelete();
            $table->date('day_pt');
            $table->unsignedInteger('reserved_units');
            $table->unsignedInteger('consumed_units')->default(0);
            $table->enum('status', ['active', 'released', 'expired']);
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->unique(['creation_id', 'day_pt']);
            $table->index(['day_pt', 'status']);
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            foreach (self::ID_COLUMNS as $table => $columns) {
                $parts = [];
                foreach ($columns as $column => $type) {
                    $parts[] = "MODIFY `{$column}` ".sprintf($type, 'CHARACTER SET utf8mb4 COLLATE utf8mb4_bin');
                }
                DB::statement("ALTER TABLE `{$table}` ".implode(', ', $parts));
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('quota_reservations');
        Schema::dropIfExists('playlist_creation_items');
        Schema::dropIfExists('playlist_creations');
    }
};

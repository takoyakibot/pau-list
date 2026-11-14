<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Final review of Phase 3:
 * - reconcile_failures: consecutive reconcile reads (playlists.list / playlistItems.list) that failed with a
 *   non-transient error; after CreationRunner::MAX_RECONCILE_FAILURES the creation goes to needs_check instead
 *   of reading forever. Reset by every successful reconcile read.
 * - The standalone status index of playlist_creation_items is dropped: every query filters by creation_id first
 *   (the (creation_id, position) unique index), and a status-only index lets InnoDB range updates of one creation
 *   take locks across other creations' rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('playlist_creations', function (Blueprint $table) {
            $table->unsignedTinyInteger('reconcile_failures')->default(0);
        });
        Schema::table('playlist_creation_items', function (Blueprint $table) {
            $table->dropIndex(['status']);
        });
    }

    public function down(): void
    {
        Schema::table('playlist_creation_items', function (Blueprint $table) {
            $table->index('status');
        });
        Schema::table('playlist_creations', function (Blueprint $table) {
            $table->dropColumn('reconcile_failures');
        });
    }
};

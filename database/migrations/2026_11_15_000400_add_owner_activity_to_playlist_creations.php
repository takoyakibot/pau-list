<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Final review of Phase 4 (policy I1, Developer Policies III.D.2): data obtained with the owner's token is deleted
 * within 7 days after the owner last used it (or logged out). The daily batch deletes the creation's items and
 * drops a non-public playlist id; the counts and whether a playlist was created stay so S4/S6 still show the result.
 * - owner_last_active_at: the logged-in owner's last start / step / operation / S6 view / logout. Existing rows
 *   start from updated_at.
 * - authorized_data_pruned_at: when the items were deleted (null while they are kept).
 * - pruned_had_playlist and *_count: what S4/S6 show afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('playlist_creations', function (Blueprint $table) {
            $table->dateTime('owner_last_active_at')->nullable();
            $table->dateTime('authorized_data_pruned_at')->nullable();
            $table->boolean('pruned_had_playlist')->default(false);
            $table->unsignedSmallInteger('done_count')->nullable();
            $table->unsignedSmallInteger('failed_count')->nullable();
            $table->unsignedSmallInteger('skipped_count')->nullable();
            $table->unsignedSmallInteger('total_count')->nullable();
        });

        DB::table('playlist_creations')->whereNull('owner_last_active_at')->update([
            'owner_last_active_at' => DB::raw('updated_at'),
        ]);
    }

    public function down(): void
    {
        Schema::table('playlist_creations', function (Blueprint $table) {
            $table->dropColumn([
                'owner_last_active_at', 'authorized_data_pruned_at', 'pruned_had_playlist',
                'done_count', 'failed_count', 'skipped_count', 'total_count',
            ]);
        });
    }
};

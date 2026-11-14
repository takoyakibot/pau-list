<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The PT day on which Google answered quotaExceeded (pause_reason quota_exceeded): resume waits for a later
 * PT day (spec 7.4). A dedicated column instead of deriving the day from updated_at, which any later write moves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('playlist_creations', function (Blueprint $table) {
            $table->date('paused_day_pt')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('playlist_creations', function (Blueprint $table) {
            $table->dropColumn('paused_day_pt');
        });
    }
};

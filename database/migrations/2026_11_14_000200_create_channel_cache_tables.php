<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_caches', function (Blueprint $table) {
            $table->string('channel_id', 24)->primary();
            $table->string('handle')->nullable()->index();
            $table->string('title');
            $table->string('thumbnail_url')->nullable();
            $table->string('uploads_playlist_id');
            $table->dateTime('meta_fetched_at');
            $table->dateTime('cached_since');
            $table->dateTime('last_synced_at');
            $table->boolean('truncated')->default(false);
            $table->dateTime('expires_at')->index();
        });

        Schema::create('channel_cache_videos', function (Blueprint $table) {
            $table->string('channel_id', 24);
            $table->char('video_id', 11);
            $table->string('title');
            $table->string('thumbnail_url')->nullable();
            $table->dateTime('published_at')->nullable();
            $table->dateTime('fetched_at');
            $table->primary(['channel_id', 'video_id']);
            $table->index(['channel_id', 'published_at']);
            $table->foreign('channel_id')->references('channel_id')->on('channel_caches')->cascadeOnDelete();
        });

        // Holds no API-derived data: only the channel id and when its cache was expired.
        Schema::create('channel_cache_expirations', function (Blueprint $table) {
            $table->string('channel_id', 24)->primary();
            $table->dateTime('expired_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_cache_videos');
        Schema::dropIfExists('channel_cache_expirations');
        Schema::dropIfExists('channel_caches');
    }
};

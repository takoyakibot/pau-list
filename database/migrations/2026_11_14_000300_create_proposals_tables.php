<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proposals', function (Blueprint $table) {
            $table->id();
            // SHA-256 (hex) of the public key. The raw key is never stored.
            $table->char('key_hash', 64)->unique();
            $table->string('channel_id', 24);
            $table->string('channel_title');
            $table->string('channel_thumbnail_url')->nullable();
            $table->string('title', 150);
            $table->text('description');
            $table->text('comment')->nullable();
            $table->string('proposer_name', 30)->nullable();
            $table->unsignedSmallInteger('item_count');
            $table->dateTime('created_at');
            $table->dateTime('expires_at')->index();
        });

        Schema::create('proposal_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proposal_id')->constrained('proposals')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->char('video_id', 11);
            $table->string('video_channel_id', 24);
            $table->string('video_channel_title');
            $table->boolean('is_outside_channel');
            $table->string('title');
            $table->string('thumbnail_url')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->dateTime('published_at')->nullable();
            $table->unique(['proposal_id', 'position']);
            $table->unique(['proposal_id', 'video_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proposal_items');
        Schema::dropIfExists('proposals');
    }
};

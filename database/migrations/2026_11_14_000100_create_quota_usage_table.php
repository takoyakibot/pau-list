<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quota_usage', function (Blueprint $table) {
            $table->date('day_pt');
            $table->enum('bucket', ['read', 'owner_read', 'write']);
            $table->unsignedInteger('units')->default(0);
            $table->primary(['day_pt', 'bucket']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quota_usage');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('watch_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('media_type', 16);
            $table->unsignedBigInteger('media_id');
            $table->string('episode_key', 64)->default('movie');
            $table->unsignedBigInteger('episode_id')->nullable();
            $table->unsignedSmallInteger('season_number')->nullable();
            $table->unsignedSmallInteger('episode_number')->nullable();
            $table->decimal('current_time', 12, 3)->default(0);
            $table->unsignedInteger('duration')->default(0);
            $table->decimal('percent_watched', 5, 2)->default(0);
            $table->boolean('is_completed')->default(false);
            $table->string('platform', 16)->default('WEB');
            $table->string('device_id', 64)->nullable();
            $table->timestamp('last_watched_at');
            $table->timestamps();

            $table->unique(['user_id', 'media_id', 'episode_key']);
            $table->index(['user_id', 'last_watched_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('watch_progress');
    }
};

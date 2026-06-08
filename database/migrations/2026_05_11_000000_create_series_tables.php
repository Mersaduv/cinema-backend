<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('series', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->string('original_title')->nullable();
            $table->text('synopsis')->nullable();
            $table->date('first_air_date')->nullable();
            $table->date('last_air_date')->nullable();
            $table->unsignedSmallInteger('release_year')->nullable();
            $table->string('status')->nullable();
            $table->string('show_type')->nullable();
            $table->unsignedSmallInteger('episode_runtime_minutes')->nullable();
            $table->unsignedSmallInteger('number_of_seasons')->nullable();
            $table->unsignedSmallInteger('number_of_episodes')->nullable();
            $table->string('network')->nullable();
            $table->string('provider_name')->nullable();
            $table->string('poster_url', 2048)->nullable();
            $table->string('backdrop_url', 2048)->nullable();
            $table->string('trailer_url', 2048)->nullable();
            $table->string('age_rating')->nullable();
            $table->string('catalog_visibility')->default('catalog_search');
            $table->boolean('is_featured')->default(false);
            $table->string('publish_status')->default('draft');
            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('series_seasons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('series_id')->constrained('series')->cascadeOnDelete();
            $table->unsignedSmallInteger('season_number');
            $table->string('name')->nullable();
            $table->text('synopsis')->nullable();
            $table->date('air_date')->nullable();
            $table->unsignedSmallInteger('episode_count')->nullable();
            $table->string('poster_url', 2048)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['series_id', 'season_number']);
        });

        Schema::create('series_episodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('series_id')->constrained('series')->cascadeOnDelete();
            $table->foreignId('series_season_id')->nullable()->constrained('series_seasons')->nullOnDelete();
            $table->unsignedSmallInteger('season_number');
            $table->unsignedSmallInteger('episode_number');
            $table->string('title');
            $table->string('slug')->index();
            $table->text('synopsis')->nullable();
            $table->date('air_date')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->string('still_url', 2048)->nullable();
            $table->string('source_method')->default('hls');
            $table->string('video_url', 2048)->nullable();
            $table->string('embed_url', 2048)->nullable();
            $table->string('hls_url', 2048)->nullable();
            $table->string('subtitle_url', 2048)->nullable();
            $table->string('dubbed_audio_url', 2048)->nullable();
            $table->string('publish_status')->default('draft');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['series_id', 'season_number', 'episode_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('series_episodes');
        Schema::dropIfExists('series_seasons');
        Schema::dropIfExists('series');
    }
};

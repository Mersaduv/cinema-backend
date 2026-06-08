<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('genres', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('tmdb_id')->nullable()->unique();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('type', 16)->default('both');
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->timestamps();

            $table->index('type');
        });

        Schema::create('production_countries', function (Blueprint $table) {
            $table->id();
            $table->string('iso_3166_1', 2)->unique();
            $table->string('name');
            $table->string('native_name')->nullable();
            $table->string('flag_emoji', 8)->nullable();
            $table->timestamps();
        });

        Schema::create('spoken_languages', function (Blueprint $table) {
            $table->id();
            $table->string('iso_639_1', 8)->unique();
            $table->string('name');
            $table->string('english_name')->nullable();
            $table->timestamps();
        });

        Schema::create('movie_genre', function (Blueprint $table) {
            $table->foreignId('movie_id')->constrained('movies')->cascadeOnDelete();
            $table->foreignId('genre_id')->constrained('genres')->cascadeOnDelete();
            $table->primary(['movie_id', 'genre_id']);
            $table->index(['genre_id', 'movie_id']);
        });

        Schema::create('series_genre', function (Blueprint $table) {
            $table->foreignId('series_id')->constrained('series')->cascadeOnDelete();
            $table->foreignId('genre_id')->constrained('genres')->cascadeOnDelete();
            $table->primary(['series_id', 'genre_id']);
            $table->index(['genre_id', 'series_id']);
        });

        Schema::table('movies', function (Blueprint $table) {
            if (! Schema::hasColumn('movies', 'origin_country')) {
                $table->string('origin_country', 10)->nullable()->after('release_year');
            }
            if (! Schema::hasColumn('movies', 'original_language')) {
                $table->string('original_language', 10)->nullable()->after('origin_country');
            }
            if (! Schema::hasColumn('movies', 'content_rating')) {
                $table->string('content_rating', 20)->nullable()->after('original_language');
            }
            $table->index('origin_country', 'movies_origin_country_idx');
            $table->index('original_language', 'movies_original_language_idx');
        });

        Schema::table('series', function (Blueprint $table) {
            if (! Schema::hasColumn('series', 'origin_country')) {
                $table->string('origin_country', 10)->nullable()->after('release_year');
            }
            if (! Schema::hasColumn('series', 'original_language')) {
                $table->string('original_language', 10)->nullable()->after('origin_country');
            }
            if (! Schema::hasColumn('series', 'content_rating')) {
                $table->string('content_rating', 20)->nullable()->after('original_language');
            }
            $table->index('origin_country', 'series_origin_country_idx');
            $table->index('original_language', 'series_original_language_idx');
        });
    }

    public function down(): void
    {
        Schema::table('series', function (Blueprint $table) {
            if (Schema::hasColumn('series', 'origin_country')) {
                $table->dropIndex('series_origin_country_idx');
                $table->dropIndex('series_original_language_idx');
                $table->dropColumn(['origin_country', 'original_language', 'content_rating']);
            }
        });

        Schema::table('movies', function (Blueprint $table) {
            if (Schema::hasColumn('movies', 'origin_country')) {
                $table->dropIndex('movies_origin_country_idx');
                $table->dropIndex('movies_original_language_idx');
                $table->dropColumn(['origin_country', 'original_language', 'content_rating']);
            }
        });

        Schema::dropIfExists('series_genre');
        Schema::dropIfExists('movie_genre');
        Schema::dropIfExists('spoken_languages');
        Schema::dropIfExists('production_countries');
        Schema::dropIfExists('genres');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('series', 'seasons_count')) {
            Schema::table('series', function (Blueprint $table): void {
                $table->unsignedSmallInteger('seasons_count')->default(0)->after('has_dubbed');
                $table->unsignedSmallInteger('episodes_count')->default(0)->after('seasons_count');
            });
        }

        DB::statement('
            UPDATE series s
            SET
                s.seasons_count = (
                    SELECT COUNT(*) FROM series_seasons ss WHERE ss.series_id = s.id
                ),
                s.episodes_count = (
                    SELECT COUNT(*) FROM series_episodes se WHERE se.series_id = s.id
                )
        ');

        Schema::table('series', function (Blueprint $table): void {
            if (! $this->indexExists('series', 'series_catalog_idx')) {
                $table->index(['publish_status', 'published_at', 'id'], 'series_catalog_idx');
            }
            if (! $this->indexExists('series', 'series_admin_idx')) {
                $table->index(['publish_status', 'created_at', 'id'], 'series_admin_idx');
            }
            if (! $this->indexExists('series', 'series_rating_idx')) {
                $table->index(['filter_rating', 'published_at'], 'series_rating_idx');
            }
            if (! $this->indexExists('series', 'series_year_idx')) {
                $table->index(['release_year', 'published_at'], 'series_year_idx');
            }
            if (! $this->indexExists('series', 'series_status_pub_idx')) {
                $table->index(['status', 'publish_status'], 'series_status_pub_idx');
            }
        });

        Schema::table('movies', function (Blueprint $table): void {
            if (! $this->indexExists('movies', 'movies_admin_list_idx')) {
                $table->index(
                    ['publish_status', 'processing_status', 'created_at', 'id'],
                    'movies_admin_list_idx',
                );
            }
            if (! $this->indexExists('movies', 'movies_catalog_pub_idx')) {
                $table->index(['publish_status', 'published_at', 'id'], 'movies_catalog_pub_idx');
            }
            if (! $this->indexExists('movies', 'movies_catalog_rating_idx')) {
                $table->index(['publish_status', 'filter_rating', 'id'], 'movies_catalog_rating_idx');
            }
            if (! $this->indexExists('movies', 'movies_catalog_year_idx')) {
                $table->index(['publish_status', 'release_year', 'id'], 'movies_catalog_year_idx');
            }
            if (! $this->indexExists('movies', 'movies_catalog_country_idx')) {
                $table->index(['publish_status', 'origin_country', 'published_at'], 'movies_catalog_country_idx');
            }
            if (! $this->indexExists('movies', 'movies_catalog_lang_idx')) {
                $table->index(['publish_status', 'original_language', 'published_at'], 'movies_catalog_lang_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('series', function (Blueprint $table): void {
            foreach ([
                'series_catalog_idx',
                'series_admin_idx',
                'series_rating_idx',
                'series_year_idx',
                'series_status_pub_idx',
            ] as $index) {
                if ($this->indexExists('series', $index)) {
                    $table->dropIndex($index);
                }
            }

            if (Schema::hasColumn('series', 'episodes_count')) {
                $table->dropColumn('episodes_count');
            }
            if (Schema::hasColumn('series', 'seasons_count')) {
                $table->dropColumn('seasons_count');
            }
        });

        Schema::table('movies', function (Blueprint $table): void {
            foreach ([
                'movies_admin_list_idx',
                'movies_catalog_pub_idx',
                'movies_catalog_rating_idx',
                'movies_catalog_year_idx',
                'movies_catalog_country_idx',
                'movies_catalog_lang_idx',
            ] as $index) {
                if ($this->indexExists('movies', $index)) {
                    $table->dropIndex($index);
                }
            }
        });
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $rows = Schema::getConnection()->select(
            "SHOW INDEX FROM `{$table}` WHERE Key_name = ?",
            [$indexName],
        );

        return count($rows) > 0;
    }
};

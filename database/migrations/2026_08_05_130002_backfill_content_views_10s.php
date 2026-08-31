<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('content_view_keys') || ! Schema::hasTable('watch_progress')) {
            return;
        }

        if (! Schema::hasColumn('watch_progress', 'current_time') || ! Schema::hasColumn('watch_progress', 'viewer_id')) {
            return;
        }

        // Backfill (for existing watch_progress rows) using the new 10s threshold.
        // We only insert keys for published content so the admin badge stays consistent
        // with what ContentViewService counts going forward.
        DB::statement("
            INSERT IGNORE INTO content_view_keys (media_type, media_id, viewer_key, created_at)
            SELECT DISTINCT
                'movie' AS media_type,
                wp.media_id AS media_id,
                CONCAT('v:', wp.viewer_id) AS viewer_key,
                COALESCE(wp.last_watched_at, NOW()) AS created_at
            FROM watch_progress wp
            INNER JOIN movies m ON m.id = wp.media_id AND m.publish_status = 'published'
            WHERE wp.media_type = 'movie'
              AND wp.viewer_id IS NOT NULL
              AND wp.current_time >= ?

            UNION ALL

            SELECT DISTINCT
                'series' AS media_type,
                wp.media_id AS media_id,
                CONCAT('v:', wp.viewer_id) AS viewer_key,
                COALESCE(wp.last_watched_at, NOW()) AS created_at
            FROM watch_progress wp
            INNER JOIN series s ON s.id = wp.media_id AND s.publish_status = 'published'
            WHERE wp.media_type = 'series'
              AND wp.viewer_id IS NOT NULL
              AND wp.current_time >= ?
        ", [10, 10]);

        // Recalculate denormalized counters.
        if (Schema::hasColumn('movies', 'views_count')) {
            DB::statement("
                UPDATE movies m
                SET m.views_count = (
                    SELECT COUNT(*)
                    FROM content_view_keys cvk
                    WHERE cvk.media_type = 'movie'
                      AND cvk.media_id = m.id
                )
                WHERE m.id IS NOT NULL
            ");
        }

        if (Schema::hasColumn('series', 'views_count')) {
            DB::statement("
                UPDATE series s
                SET s.views_count = (
                    SELECT COUNT(*)
                    FROM content_view_keys cvk
                    WHERE cvk.media_type = 'series'
                      AND cvk.media_id = s.id
                )
                WHERE s.id IS NOT NULL
            ");
        }
    }

    public function down(): void
    {
        // Non-reversible.
    }
};


<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movies', function (Blueprint $table) {
            if (! Schema::hasColumn('movies', 'views_count')) {
                $table->unsignedBigInteger('views_count')->default(0)->after('vote_count');
            }
        });

        Schema::table('series', function (Blueprint $table) {
            if (! Schema::hasColumn('series', 'views_count')) {
                $table->unsignedBigInteger('views_count')->default(0)->after('vote_count');
            }
        });

        if (! Schema::hasTable('content_view_keys')) {
            Schema::create('content_view_keys', function (Blueprint $table) {
                $table->id();
                $table->string('media_type', 20);
                $table->unsignedBigInteger('media_id');
                $table->string('viewer_key', 80);
                $table->timestamp('created_at')->useCurrent();

                $table->unique(['media_type', 'media_id', 'viewer_key'], 'uq_content_view_keys');
                $table->index(['media_type', 'media_id'], 'idx_content_view_keys_media');
            });
        }

        if (Schema::hasTable('watch_progress')) {
            DB::statement("
                INSERT IGNORE INTO content_view_keys (media_type, media_id, viewer_key, created_at)
                SELECT DISTINCT
                    wp.media_type,
                    wp.media_id,
                    CONCAT('v:', wp.viewer_id),
                    COALESCE(wp.last_watched_at, NOW())
                FROM watch_progress wp
                WHERE wp.viewer_id IS NOT NULL
                  AND wp.current_time >= 30
                  AND wp.media_type IN ('movie', 'series')
            ");

            foreach (['movie' => 'movies', 'series' => 'series'] as $mediaType => $tableName) {
                $rows = DB::table('content_view_keys')
                    ->where('media_type', $mediaType)
                    ->select('media_id', DB::raw('COUNT(*) AS aggregate'))
                    ->groupBy('media_id')
                    ->get();

                foreach ($rows as $row) {
                    DB::table($tableName)
                        ->where('id', $row->media_id)
                        ->update(['views_count' => (int) $row->aggregate]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('content_view_keys');

        Schema::table('movies', function (Blueprint $table) {
            if (Schema::hasColumn('movies', 'views_count')) {
                $table->dropColumn('views_count');
            }
        });

        Schema::table('series', function (Blueprint $table) {
            if (Schema::hasColumn('series', 'views_count')) {
                $table->dropColumn('views_count');
            }
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movies', function (Blueprint $table): void {
            if (! $this->indexExists('movies', 'movies_publish_status_idx')) {
                $table->index('publish_status', 'movies_publish_status_idx');
            }
            if (! $this->indexExists('movies', 'movies_processing_status_idx')) {
                $table->index('processing_status', 'movies_processing_status_idx');
            }
            if (! $this->indexExists('movies', 'movies_publish_created_idx')) {
                $table->index(['publish_status', 'created_at'], 'movies_publish_created_idx');
            }
        });

        Schema::table('series', function (Blueprint $table): void {
            if (! $this->indexExists('series', 'series_publish_status_idx')) {
                $table->index('publish_status', 'series_publish_status_idx');
            }
            if (! $this->indexExists('series', 'series_status_idx')) {
                $table->index('status', 'series_status_idx');
            }
            if (! $this->indexExists('series', 'series_is_featured_idx')) {
                $table->index('is_featured', 'series_is_featured_idx');
            }
            if (! $this->indexExists('series', 'series_publish_created_idx')) {
                $table->index(['publish_status', 'created_at'], 'series_publish_created_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('movies', function (Blueprint $table): void {
            foreach ([
                'movies_publish_status_idx',
                'movies_processing_status_idx',
                'movies_publish_created_idx',
            ] as $index) {
                if ($this->indexExists('movies', $index)) {
                    $table->dropIndex($index);
                }
            }
        });

        Schema::table('series', function (Blueprint $table): void {
            foreach ([
                'series_publish_status_idx',
                'series_status_idx',
                'series_is_featured_idx',
                'series_publish_created_idx',
            ] as $index) {
                if ($this->indexExists('series', $index)) {
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

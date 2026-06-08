<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('series', 'has_subtitle')) {
            Schema::table('series', function (Blueprint $table): void {
                $table->boolean('has_subtitle')->default(false)->after('number_of_episodes');
            });
        }

        if (! Schema::hasColumn('series', 'has_dubbed')) {
            Schema::table('series', function (Blueprint $table): void {
                $table->boolean('has_dubbed')->default(false)->after('has_subtitle');
            });
        }

        Schema::table('series', function (Blueprint $table): void {
            if (! $this->indexExists('series', 'series_has_subtitle_idx')) {
                $table->index('has_subtitle', 'series_has_subtitle_idx');
            }
            if (! $this->indexExists('series', 'series_has_dubbed_idx')) {
                $table->index('has_dubbed', 'series_has_dubbed_idx');
            }
        });

        DB::statement(
            "UPDATE series
             SET has_subtitle = EXISTS (
                 SELECT 1 FROM series_episodes
                 WHERE series_episodes.series_id = series.id
                   AND series_episodes.subtitle_url IS NOT NULL
                   AND series_episodes.subtitle_url <> ''
             )"
        );

        DB::statement(
            "UPDATE series
             SET has_dubbed = EXISTS (
                 SELECT 1 FROM series_episodes
                 WHERE series_episodes.series_id = series.id
                   AND series_episodes.dubbed_audio_url IS NOT NULL
                   AND series_episodes.dubbed_audio_url <> ''
             )"
        );
    }

    public function down(): void
    {
        Schema::table('series', function (Blueprint $table): void {
            if ($this->indexExists('series', 'series_has_subtitle_idx')) {
                $table->dropIndex('series_has_subtitle_idx');
            }
            if ($this->indexExists('series', 'series_has_dubbed_idx')) {
                $table->dropIndex('series_has_dubbed_idx');
            }

            $columns = array_values(array_filter([
                Schema::hasColumn('series', 'has_subtitle') ? 'has_subtitle' : null,
                Schema::hasColumn('series', 'has_dubbed') ? 'has_dubbed' : null,
            ]));

            if ($columns !== []) {
                $table->dropColumn($columns);
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

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addFilterColumns('movies');
        $this->addFilterColumns('series');
    }

    public function down(): void
    {
        $this->dropFilterColumns('movies', 'movies');
        $this->dropFilterColumns('series', 'series');
    }

    private function addFilterColumns(string $table): void
    {
        if (! Schema::hasColumn($table, 'filter_rating')) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->decimal('filter_rating', 3, 1)->nullable()->after('release_year');
            });
        }

        if (! Schema::hasColumn($table, 'filter_primary_genre')) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->string('filter_primary_genre', 120)->nullable()->after('filter_rating');
            });
        }

        if (! Schema::hasColumn($table, 'filter_primary_country')) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->string('filter_primary_country', 120)->nullable()->after('filter_primary_genre');
            });
        }

        if (! Schema::hasColumn($table, 'filter_primary_network')) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->string('filter_primary_network', 255)->nullable()->after('filter_primary_country');
            });
        }

        Schema::table($table, function (Blueprint $blueprint) use ($table): void {
            if (! $this->indexExists($table, "{$table}_release_year_idx")) {
                $blueprint->index('release_year', "{$table}_release_year_idx");
            }
            if (! $this->indexExists($table, "{$table}_filter_rating_idx")) {
                $blueprint->index('filter_rating', "{$table}_filter_rating_idx");
            }
            if (! $this->indexExists($table, "{$table}_filter_primary_genre_idx")) {
                $blueprint->index('filter_primary_genre', "{$table}_filter_primary_genre_idx");
            }
            if (! $this->indexExists($table, "{$table}_filter_primary_country_idx")) {
                $blueprint->index('filter_primary_country', "{$table}_filter_primary_country_idx");
            }
        });

        $networkIndex = "{$table}_filter_primary_network_idx";
        if (! $this->indexExists($table, $networkIndex)) {
            Schema::getConnection()->statement(
                "CREATE INDEX `{$networkIndex}` ON `{$table}` (`filter_primary_network`(191))"
            );
        }
    }

    private function dropFilterColumns(string $table, string $prefix): void
    {
        Schema::table($table, function (Blueprint $blueprint) use ($table, $prefix): void {
            foreach ([
                "{$prefix}_release_year_idx",
                "{$prefix}_filter_rating_idx",
                "{$prefix}_filter_primary_genre_idx",
                "{$prefix}_filter_primary_country_idx",
                "{$prefix}_filter_primary_network_idx",
            ] as $index) {
                if ($this->indexExists($table, $index)) {
                    $blueprint->dropIndex($index);
                }
            }

            $columns = array_values(array_filter([
                Schema::hasColumn($table, 'filter_rating') ? 'filter_rating' : null,
                Schema::hasColumn($table, 'filter_primary_genre') ? 'filter_primary_genre' : null,
                Schema::hasColumn($table, 'filter_primary_country') ? 'filter_primary_country' : null,
                Schema::hasColumn($table, 'filter_primary_network') ? 'filter_primary_network' : null,
            ]));

            if ($columns !== []) {
                $blueprint->dropColumn($columns);
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

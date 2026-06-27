<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['movies', 'series'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                if (! Schema::hasColumn($table, 'filter_popularity')) {
                    $blueprint->decimal('filter_popularity', 10, 3)->nullable()->after('filter_rating');
                    $blueprint->index('filter_popularity', "{$table}_filter_popularity_idx");
                }

                if (! Schema::hasColumn($table, 'vote_count')) {
                    $blueprint->unsignedInteger('vote_count')->nullable()->after('filter_popularity');
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['movies', 'series'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                if (Schema::hasColumn($table, 'vote_count')) {
                    $blueprint->dropColumn('vote_count');
                }

                if (Schema::hasColumn($table, 'filter_popularity')) {
                    $blueprint->dropIndex("{$table}_filter_popularity_idx");
                    $blueprint->dropColumn('filter_popularity');
                }
            });
        }
    }
};

<?php

use App\Support\CatalogPublicProcedureInstaller;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['movies', 'series'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                if (! Schema::hasColumn($table, 'trending_rank')) {
                    $blueprint->unsignedInteger('trending_rank')->nullable()->after('vote_count');
                    $blueprint->index('trending_rank', "{$table}_trending_rank_idx");
                }

                if (! Schema::hasColumn($table, 'trending_window')) {
                    $blueprint->string('trending_window', 10)->nullable()->after('trending_rank');
                }

                if (! Schema::hasColumn($table, 'trending_synced_at')) {
                    $blueprint->timestamp('trending_synced_at')->nullable()->after('trending_window');
                }
            });
        }

        CatalogPublicProcedureInstaller::install();
    }

    public function down(): void
    {
        foreach (['movies', 'series'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                if (Schema::hasColumn($table, 'trending_synced_at')) {
                    $blueprint->dropColumn('trending_synced_at');
                }

                if (Schema::hasColumn($table, 'trending_window')) {
                    $blueprint->dropColumn('trending_window');
                }

                if (Schema::hasColumn($table, 'trending_rank')) {
                    $blueprint->dropIndex("{$table}_trending_rank_idx");
                    $blueprint->dropColumn('trending_rank');
                }
            });
        }
    }
};

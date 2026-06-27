<?php

use App\Support\CatalogPublicProcedureInstaller;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('content_play_stats')) {
            Schema::create('content_play_stats', function (Blueprint $table): void {
                $table->id();
                $table->string('media_type', 16);
                $table->unsignedBigInteger('media_id');
                $table->unsignedInteger('plays_7d')->default(0);
                $table->unsignedInteger('plays_30d')->default(0);
                $table->timestamp('aggregated_at')->nullable();
                $table->timestamps();

                $table->unique(['media_type', 'media_id']);
                $table->index(['media_type', 'plays_30d']);
            });
        }

        foreach (['movies', 'series'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                if (! Schema::hasColumn($table, 'internal_popularity_7d')) {
                    $blueprint->unsignedInteger('internal_popularity_7d')->default(0)->after('trending_synced_at');
                }

                if (! Schema::hasColumn($table, 'internal_popularity_30d')) {
                    $blueprint->unsignedInteger('internal_popularity_30d')->default(0)->after('internal_popularity_7d');
                    $blueprint->index('internal_popularity_30d', "{$table}_internal_pop_30d_idx");
                }

                if (! Schema::hasColumn($table, 'popular_hybrid_score')) {
                    $blueprint->decimal('popular_hybrid_score', 12, 6)->nullable()->after('internal_popularity_30d');
                    $blueprint->index('popular_hybrid_score', "{$table}_popular_hybrid_idx");
                }
            });
        }

        CatalogPublicProcedureInstaller::install();
    }

    public function down(): void
    {
        foreach (['movies', 'series'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                foreach (['popular_hybrid_score', 'internal_popularity_30d', 'internal_popularity_7d'] as $column) {
                    if (Schema::hasColumn($table, $column)) {
                        if ($column === 'internal_popularity_30d') {
                            $blueprint->dropIndex("{$table}_internal_pop_30d_idx");
                        }
                        if ($column === 'popular_hybrid_score') {
                            $blueprint->dropIndex("{$table}_popular_hybrid_idx");
                        }
                        $blueprint->dropColumn($column);
                    }
                }
            });
        }

        Schema::dropIfExists('content_play_stats');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('movies', 'cached_avg_rating')) {
            Schema::table('movies', function (Blueprint $table): void {
                $table->decimal('cached_avg_rating', 4, 1)->nullable()->after('filter_rating');
            });
        }

        if (! Schema::hasColumn('series', 'cached_avg_rating')) {
            Schema::table('series', function (Blueprint $table): void {
                $table->decimal('cached_avg_rating', 4, 1)->nullable()->after('filter_rating');
            });
        }

        Schema::create('reviews', function (Blueprint $table): void {
            $table->id();
            $table->enum('reviewable_type', ['movie', 'series']);
            $table->unsignedBigInteger('reviewable_id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('body')->nullable();
            $table->boolean('is_approved')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'reviewable_type', 'reviewable_id'], 'uq_user_reviewable');
            $table->index(['reviewable_type', 'reviewable_id', 'is_approved', 'created_at'], 'idx_reviewable');
            $table->index('user_id');
        });

        if (Schema::hasTable('movies')) {
            Schema::table('movies', function (Blueprint $table): void {
                if (! $this->indexExists('movies', 'idx_movies_related')) {
                    $table->index(
                        ['publish_status', 'filter_primary_genre', 'filter_rating', 'origin_country'],
                        'idx_movies_related',
                    );
                }
            });
        }

        if (Schema::hasTable('series')) {
            Schema::table('series', function (Blueprint $table): void {
                if (! $this->indexExists('series', 'idx_series_related')) {
                    $table->index(
                        ['publish_status', 'filter_primary_genre', 'status', 'filter_primary_network'],
                        'idx_series_related',
                    );
                }
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');

        if (Schema::hasColumn('movies', 'cached_avg_rating')) {
            Schema::table('movies', function (Blueprint $table): void {
                $table->dropColumn('cached_avg_rating');
            });
        }

        if (Schema::hasColumn('series', 'cached_avg_rating')) {
            Schema::table('series', function (Blueprint $table): void {
                $table->dropColumn('cached_avg_rating');
            });
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        $connection = Schema::getConnection();
        $database = $connection->getDatabaseName();

        $result = $connection->select(
            'SELECT 1 FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ? LIMIT 1',
            [$database, $table, $index],
        );

        return $result !== [];
    }
};

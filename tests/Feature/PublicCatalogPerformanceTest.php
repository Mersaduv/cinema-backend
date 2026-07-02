<?php

namespace Tests\Feature;

use App\Models\Movie;
use App\Models\Series;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PublicCatalogPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_movies_catalog_completes_under_threshold_on_cache_miss(): void
    {
        for ($index = 1; $index <= 30; $index++) {
            Movie::query()->create([
                'title' => "Catalog Movie {$index}",
                'slug' => "catalog-movie-{$index}",
                'media_folder' => "movies/catalog-movie-{$index}",
                'publish_status' => 'published',
                'published_at' => now(),
                'metadata' => [],
            ]);
        }

        DB::enableQueryLog();
        $startedAt = microtime(true);

        $response = $this->getJson('/api/movies?page=1&per_page=24');

        $elapsedMs = (microtime(true) - $startedAt) * 1000;
        $queryCount = count(DB::getQueryLog());

        $response->assertOk()->assertJsonPath('success', true);

        $this->assertLessThan(600, $elapsedMs, "Movies catalog took {$elapsedMs}ms");
        $this->assertLessThanOrEqual(5, $queryCount, "Movies catalog ran {$queryCount} queries");
    }

    public function test_public_series_catalog_avoids_per_row_episode_queries(): void
    {
        for ($index = 1; $index <= 20; $index++) {
            Series::query()->create([
                'title' => "Catalog Series {$index}",
                'slug' => "catalog-series-{$index}",
                'publish_status' => 'published',
                'published_at' => now(),
                'metadata' => [],
            ]);
        }

        DB::enableQueryLog();
        $startedAt = microtime(true);

        $response = $this->getJson('/api/series?page=1&per_page=24');

        $elapsedMs = (microtime(true) - $startedAt) * 1000;
        $queryLog = DB::getQueryLog();
        $episodeQueries = array_filter(
            $queryLog,
            fn (array $entry) => str_contains(strtolower($entry['query']), 'series_episodes'),
        );

        $response->assertOk()->assertJsonPath('success', true);

        $this->assertLessThan(600, $elapsedMs, "Series catalog took {$elapsedMs}ms");
        $this->assertCount(0, $episodeQueries, 'Series catalog must not query series_episodes per row');
    }
}

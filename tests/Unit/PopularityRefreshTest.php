<?php

namespace Tests\Unit;

use App\Models\Movie;
use App\Models\User;
use App\Models\WatchProgress;
use App\Services\Cinema\CatalogRankingService;
use App\Services\Cinema\InternalPopularityAggregationService;
use App\Services\Cinema\TmdbPopularityRefreshService;
use App\Services\TmdbService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class PopularityRefreshTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_daily_refresh_updates_filter_popularity_without_dropping_metadata(): void
    {
        $movie = Movie::query()->create([
            'title' => 'Refresh Target',
            'slug' => 'refresh-target',
            'media_folder' => 'refresh-target',
            'tmdb_id' => 42,
            'publish_status' => 'published',
            'published_at' => now(),
            'metadata' => ['tmdb' => ['rating' => 7.5], 'playback' => ['hls_url' => 'https://cdn.example/hls.m3u8']],
            'filter_popularity' => 1.0,
            'vote_count' => 10,
        ]);

        $tmdb = Mockery::mock(TmdbService::class);
        $tmdb->shouldReceive('fetchPopularitySnapshot')
            ->once()
            ->with(42, 'movie')
            ->andReturn([
                'popularity' => 88.5,
                'vote_count' => 4200,
                'vote_average' => 8.1,
            ]);

        $this->app->instance(TmdbService::class, $tmdb);

        $updated = app(TmdbPopularityRefreshService::class)->refreshPublishedCatalog();

        $this->assertSame(1, $updated);

        $movie->refresh();
        $this->assertSame(88.5, (float) $movie->filter_popularity);
        $this->assertSame(4200, (int) $movie->vote_count);
        $this->assertSame('https://cdn.example/hls.m3u8', data_get($movie->metadata, 'playback.hls_url'));
    }

    public function test_internal_popularity_aggregation_counts_watch_progress_sessions(): void
    {
        $movie = Movie::query()->create([
            'title' => 'Played Movie',
            'slug' => 'played-movie',
            'media_folder' => 'played-movie',
            'publish_status' => 'published',
            'published_at' => now(),
            'filter_popularity' => 40.0,
            'metadata' => [],
        ]);

        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();

        WatchProgress::query()->create([
            'user_id' => $firstUser->id,
            'media_type' => 'movie',
            'media_id' => $movie->id,
            'episode_key' => 'movie',
            'percent_watched' => 40,
            'last_watched_at' => now()->subDay(),
        ]);

        WatchProgress::query()->create([
            'user_id' => $secondUser->id,
            'media_type' => 'movie',
            'media_id' => $movie->id,
            'episode_key' => 'movie',
            'percent_watched' => 80,
            'last_watched_at' => now()->subDays(20),
        ]);

        $updated = app(InternalPopularityAggregationService::class)->aggregate();

        $this->assertGreaterThanOrEqual(1, $updated);

        $movie->refresh();
        $this->assertSame(1, (int) $movie->internal_popularity_7d);
        $this->assertSame(2, (int) $movie->internal_popularity_30d);
    }

    public function test_hybrid_score_blends_tmdb_and_internal_signals_by_weight(): void
    {
        config()->set('cinema.popular_hybrid_tmdb_weight', 0.6);
        config()->set('cinema.popular_hybrid_internal_weight', 0.4);

        $service = app(CatalogRankingService::class);

        $score = $service->popularHybridScore(100.0, 50);

        $this->assertSame(80.0, $score);
    }
}

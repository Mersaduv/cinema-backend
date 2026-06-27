<?php

namespace Tests\Feature;

use App\Models\Movie;
use App\Models\Series;
use App\Services\Cinema\TmdbTrendingSyncService;
use App\Services\TmdbService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class TmdbTrendingSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_trending_job_populates_trending_rank_from_mocked_tmdb_response(): void
    {
        $movie = Movie::query()->create([
            'title' => 'Fight Club',
            'slug' => 'fight-club',
            'media_folder' => 'fight-club',
            'tmdb_id' => 550,
            'publish_status' => 'published',
            'published_at' => now(),
            'metadata' => [],
        ]);

        $series = Series::query()->create([
            'title' => 'Sample Series',
            'slug' => 'sample-series',
            'tmdb_id' => 1399,
            'publish_status' => 'published',
            'published_at' => now(),
            'metadata' => [],
        ]);

        $tmdb = Mockery::mock(TmdbService::class);
        $tmdb->shouldReceive('fetchTrendingMovies')
            ->once()
            ->with('day')
            ->andReturn([
                ['id' => 550, 'rank' => 1, 'popularity' => 80.5, 'vote_average' => 8.4, 'vote_count' => 30000],
            ]);
        $tmdb->shouldReceive('fetchTrendingSeries')
            ->once()
            ->with('day')
            ->andReturn([
                ['id' => 1399, 'rank' => 2, 'popularity' => 70.2, 'vote_average' => 8.8, 'vote_count' => 12000],
            ]);

        $this->app->instance(TmdbService::class, $tmdb);

        $matched = app(TmdbTrendingSyncService::class)->refresh('day');

        $this->assertSame(2, $matched);

        $movie->refresh();
        $series->refresh();

        $this->assertSame(1, (int) $movie->trending_rank);
        $this->assertSame('day', $movie->trending_window);
        $this->assertNotNull($movie->trending_synced_at);
        $this->assertSame(2, (int) $series->trending_rank);
    }

    public function test_stale_trending_rows_are_excluded_from_sort_trending(): void
    {
        $fresh = $this->createTrendingMovie('fresh-trending', 1, now());
        $stale = $this->createTrendingMovie('stale-trending', 2, now()->subDays(5));

        $response = $this->getJson('/api/movies?sort=trending&per_page=24');
        $response->assertOk();

        $ids = collect($response->json('data.movies'))->pluck('id')->all();

        $this->assertContains($fresh->id, $ids);
        $this->assertNotSame($stale->id, $ids[0] ?? null);
    }

    private function createTrendingMovie(string $slug, int $rank, \Illuminate\Support\Carbon $syncedAt): Movie
    {
        return Movie::query()->create([
            'title' => $slug,
            'slug' => $slug,
            'media_folder' => $slug,
            'publish_status' => 'published',
            'published_at' => now(),
            'filter_popularity' => 50.0,
            'vote_count' => 1000,
            'trending_rank' => $rank,
            'trending_window' => 'day',
            'trending_synced_at' => $syncedAt,
            'metadata' => [],
        ]);
    }
}

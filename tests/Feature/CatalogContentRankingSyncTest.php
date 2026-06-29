<?php

namespace Tests\Feature;

use App\Jobs\RefreshTrendingJob;
use App\Jobs\SyncCatalogContentRankingJob;
use App\Models\Movie;
use App\Services\Cinema\CatalogContentRankingSyncService;
use App\Services\TmdbService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class CatalogContentRankingSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_apply_hybrid_score_uses_form_popularity_immediately(): void
    {
        $movie = Movie::query()->create([
            'title' => 'Hybrid Movie',
            'slug' => 'hybrid-movie',
            'media_folder' => 'hybrid-movie',
            'tmdb_id' => 550,
            'publish_status' => 'published',
            'published_at' => now(),
            'filter_popularity' => 80.0,
            'internal_popularity_30d' => 10,
            'metadata' => [],
        ]);

        app(CatalogContentRankingSyncService::class)->syncAfterAdminSave($movie);

        $movie->refresh();

        $this->assertNotNull($movie->popular_hybrid_score);
        $this->assertGreaterThan(0, (float) $movie->popular_hybrid_score);
    }

    public function test_refresh_from_tmdb_updates_popularity_and_hybrid_score(): void
    {
        $movie = Movie::query()->create([
            'title' => 'TMDB Refresh Movie',
            'slug' => 'tmdb-refresh-movie',
            'media_folder' => 'tmdb-refresh-movie',
            'tmdb_id' => 550,
            'publish_status' => 'published',
            'published_at' => now(),
            'filter_rating' => 8.5,
            'metadata' => [],
        ]);

        $tmdb = Mockery::mock(TmdbService::class);
        $tmdb->shouldReceive('fetchPopularitySnapshot')
            ->once()
            ->with(550, 'movie')
            ->andReturn([
                'popularity' => 95.5,
                'vote_count' => 31000,
                'vote_average' => 8.8,
            ]);

        $this->app->instance(TmdbService::class, $tmdb);

        $refreshed = app(CatalogContentRankingSyncService::class)->refreshFromTmdb($movie);

        $this->assertTrue($refreshed);

        $movie->refresh();

        $this->assertSame(95.5, (float) $movie->filter_popularity);
        $this->assertSame(31000, (int) $movie->vote_count);
        $this->assertSame(8.5, (float) $movie->filter_rating);
        $this->assertNotNull($movie->popular_hybrid_score);
    }

    public function test_publish_dispatches_deferred_ranking_jobs(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        $movie = Movie::query()->create([
            'title' => 'Publish Ranking Movie',
            'slug' => 'publish-ranking-movie',
            'media_folder' => 'publish-ranking-movie',
            'tmdb_id' => 550,
            'publish_status' => 'published',
            'published_at' => now(),
            'filter_popularity' => 70.0,
            'metadata' => [],
        ]);

        app(CatalogContentRankingSyncService::class)->syncOnPublish($movie);
        $this->app->terminate();

        \Illuminate\Support\Facades\Queue::assertPushed(SyncCatalogContentRankingJob::class, function ($job) use ($movie) {
            return $job->contentType === 'movie' && $job->contentId === $movie->id;
        });

        \Illuminate\Support\Facades\Queue::assertPushed(RefreshTrendingJob::class);
    }
}

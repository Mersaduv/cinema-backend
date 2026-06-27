<?php

namespace Tests\Feature;

use App\Models\Movie;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogPopularitySortTest extends TestCase
{
    use RefreshDatabase;

    public function test_sort_popular_orders_by_filter_popularity_not_published_at(): void
    {
        $olderPopular = $this->createPublishedMovie('older-popular', [
            'filter_popularity' => 120.5,
            'published_at' => now()->subDays(30),
        ]);

        $newerLessPopular = $this->createPublishedMovie('newer-less-popular', [
            'filter_popularity' => 12.3,
            'published_at' => now(),
        ]);

        $response = $this->getJson('/api/movies?sort=popular&per_page=24');

        $response->assertOk();

        $ids = collect($response->json('data.movies'))->pluck('id')->all();

        $this->assertSame(
            [$olderPopular->id, $newerLessPopular->id],
            array_slice(array_values(array_intersect($ids, [$olderPopular->id, $newerLessPopular->id])), 0, 2),
        );
    }

    public function test_sort_top_rated_deprioritizes_low_vote_count_titles(): void
    {
        $highVotes = $this->createPublishedMovie('high-votes', [
            'filter_rating' => 8.0,
            'vote_count' => 5000,
        ]);

        $lowVotesOutlier = $this->createPublishedMovie('low-votes-outlier', [
            'filter_rating' => 9.9,
            'vote_count' => 2,
        ]);

        $response = $this->getJson('/api/movies?sort=top_rated&per_page=24');
        $response->assertOk();

        $ids = collect($response->json('data.movies'))->pluck('id')->all();
        $highIndex = array_search($highVotes->id, $ids, true);
        $lowIndex = array_search($lowVotesOutlier->id, $ids, true);

        $this->assertNotFalse($highIndex);
        $this->assertNotFalse($lowIndex);
        $this->assertLessThan($lowIndex, $highIndex);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createPublishedMovie(string $slug, array $overrides = []): Movie
    {
        return Movie::query()->create(array_merge([
            'title' => $slug,
            'slug' => $slug,
            'media_folder' => $slug,
            'publish_status' => 'published',
            'published_at' => now(),
            'filter_rating' => 7.0,
            'filter_popularity' => 10.0,
            'vote_count' => 100,
            'metadata' => [],
        ], $overrides));
    }
}

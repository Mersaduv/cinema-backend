<?php

namespace Tests\Feature;

use App\Models\Movie;
use App\Models\Review;
use App\Models\Role;
use App\Models\Series;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReviewsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_read_reviews(): void
    {
        $movie = $this->createPublishedMovie();

        Review::query()->create([
            'reviewable_type' => 'movie',
            'reviewable_id' => $movie->id,
            'user_id' => User::factory()->create()->id,
            'rating' => 8,
            'body' => 'Great movie.',
            'status' => Review::STATUS_APPROVED,
            'is_approved' => true,
        ]);

        $response = $this->getJson("/api/movies/{$movie->slug}/reviews");

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.summary.total_count', 1)
            ->assertJsonPath('data.reviews.0.rating', 8);
    }

    public function test_review_body_must_be_at_least_three_characters(): void
    {
        $movie = $this->createPublishedMovie();
        $user = User::factory()->create(['status' => 'active']);
        Sanctum::actingAs($user);

        $this->postJson('/api/reviews', [
            'reviewable_type' => 'movie',
            'slug' => $movie->slug,
            'rating' => 7,
            'body' => 'ok',
        ])->assertStatus(422);
    }

    public function test_authenticated_user_can_submit_review(): void
    {
        $movie = $this->createPublishedMovie();
        $user = User::factory()->create(['status' => 'active']);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/reviews', [
            'reviewable_type' => 'movie',
            'slug' => $movie->slug,
            'rating' => 9,
            'body' => 'Excellent cinematography.',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.review.rating', 9);

        $this->assertDatabaseHas('reviews', [
            'reviewable_type' => 'movie',
            'reviewable_id' => $movie->id,
            'user_id' => $user->id,
            'rating' => 9,
            'status' => Review::STATUS_PENDING,
        ]);
    }

    public function test_user_cannot_submit_duplicate_review(): void
    {
        $movie = $this->createPublishedMovie();
        $user = User::factory()->create(['status' => 'active']);
        Sanctum::actingAs($user);

        $this->postJson('/api/reviews', [
            'reviewable_type' => 'movie',
            'slug' => $movie->slug,
            'rating' => 7,
            'body' => 'First review with enough text.',
        ])->assertCreated();

        $this->postJson('/api/reviews', [
            'reviewable_type' => 'movie',
            'slug' => $movie->slug,
            'rating' => 8,
            'body' => 'Duplicate attempt with enough text.',
        ])->assertStatus(409);
    }

    public function test_user_can_update_own_review(): void
    {
        $movie = $this->createPublishedMovie();
        $user = User::factory()->create(['status' => 'active']);
        Sanctum::actingAs($user);

        $review = Review::query()->create([
            'reviewable_type' => 'movie',
            'reviewable_id' => $movie->id,
            'user_id' => $user->id,
            'rating' => 6,
            'body' => 'Okay.',
            'status' => Review::STATUS_APPROVED,
            'is_approved' => true,
        ]);

        $this->putJson("/api/reviews/{$review->id}", [
            'rating' => 8,
            'body' => 'Better on second watch.',
        ])
            ->assertOk()
            ->assertJsonPath('data.review.rating', 8);
    }

    public function test_user_cannot_update_others_review(): void
    {
        $movie = $this->createPublishedMovie();
        $owner = User::factory()->create(['status' => 'active']);
        $other = User::factory()->create(['status' => 'active']);

        $review = Review::query()->create([
            'reviewable_type' => 'movie',
            'reviewable_id' => $movie->id,
            'user_id' => $owner->id,
            'rating' => 6,
            'body' => 'Original review text.',
            'status' => Review::STATUS_APPROVED,
            'is_approved' => true,
        ]);

        Sanctum::actingAs($other);

        $this->putJson("/api/reviews/{$review->id}", [
            'rating' => 10,
            'body' => 'Attempted hijack with enough text.',
        ])->assertForbidden();
    }

    public function test_user_can_delete_own_review(): void
    {
        $movie = $this->createPublishedMovie();
        $user = User::factory()->create(['status' => 'active']);
        Sanctum::actingAs($user);

        $review = Review::query()->create([
            'reviewable_type' => 'movie',
            'reviewable_id' => $movie->id,
            'user_id' => $user->id,
            'rating' => 5,
            'body' => 'Review to delete later.',
            'status' => Review::STATUS_APPROVED,
            'is_approved' => true,
        ]);

        $this->deleteJson("/api/reviews/{$review->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('reviews', ['id' => $review->id]);
    }

    public function test_admin_can_approve_reject_review(): void
    {
        $movie = $this->createPublishedMovie();
        $admin = $this->createAdminUser();
        Sanctum::actingAs($admin);

        $review = Review::query()->create([
            'reviewable_type' => 'movie',
            'reviewable_id' => $movie->id,
            'user_id' => User::factory()->create()->id,
            'rating' => 4,
            'body' => 'Needs moderation.',
            'status' => Review::STATUS_APPROVED,
            'is_approved' => true,
        ]);

        $this->putJson("/api/admin/reviews/{$review->id}/reject")
            ->assertOk();

        $this->assertDatabaseHas('reviews', [
            'id' => $review->id,
            'is_approved' => false,
        ]);

        $this->putJson("/api/admin/reviews/{$review->id}/approve")
            ->assertOk();

        $this->assertDatabaseHas('reviews', [
            'id' => $review->id,
            'is_approved' => true,
        ]);
    }

    public function test_review_rating_cache_updates_on_approve_and_delete(): void
    {
        $movie = $this->createPublishedMovie();
        $user = User::factory()->create(['status' => 'active']);
        $admin = $this->createAdminUser();
        Sanctum::actingAs($user);

        $this->postJson('/api/reviews', [
            'reviewable_type' => 'movie',
            'slug' => $movie->slug,
            'rating' => 8,
            'body' => 'Pending review waiting for approval.',
        ])->assertCreated();

        $movie->refresh();
        $this->assertNull($movie->cached_avg_rating);

        $review = Review::query()->where('user_id', $user->id)->firstOrFail();

        Sanctum::actingAs($admin);
        $this->putJson("/api/admin/reviews/{$review->id}/approve")->assertOk();

        $movie->refresh();
        $this->assertSame(8.0, (float) $movie->cached_avg_rating);

        Sanctum::actingAs($user);
        $this->deleteJson("/api/reviews/{$review->id}")->assertOk();

        $movie->refresh();
        $this->assertNull($movie->cached_avg_rating);
    }

    public function test_graphql_movie_query_includes_reviews(): void
    {
        $movie = $this->createPublishedMovie();

        Review::query()->create([
            'reviewable_type' => 'movie',
            'reviewable_id' => $movie->id,
            'user_id' => User::factory()->create()->id,
            'rating' => 7,
            'body' => 'Solid.',
            'status' => Review::STATUS_APPROVED,
            'is_approved' => true,
        ]);

        $response = $this->postJson('/graphql', [
            'query' => <<<'GQL'
                query ($slug: String!) {
                  movie(slug: $slug) {
                    slug
                    cached_avg_rating
                    reviews(first: 5, sort: NEWEST) {
                      summary { total_count average_rating }
                      reviews { rating body }
                      paginatorInfo { total }
                    }
                  }
                }
            GQL,
            'variables' => ['slug' => $movie->slug],
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.movie.reviews.summary.total_count', 1)
            ->assertJsonPath('data.movie.reviews.reviews.0.rating', 7)
            ->assertJsonMissingPath('errors');
    }

    public function test_graphql_series_query_includes_reviews(): void
    {
        $series = Series::query()->create([
            'title' => 'Review Series',
            'slug' => 'review-series',
            'publish_status' => 'published',
            'published_at' => now(),
            'metadata' => [],
        ]);

        Review::query()->create([
            'reviewable_type' => 'series',
            'reviewable_id' => $series->id,
            'user_id' => User::factory()->create()->id,
            'rating' => 9,
            'body' => 'Great series overall.',
            'status' => Review::STATUS_APPROVED,
            'is_approved' => true,
        ]);

        $response = $this->postJson('/graphql', [
            'query' => <<<'GQL'
                query ($slug: String!) {
                  seriesOverview(slug: $slug) {
                    slug
                    cached_avg_rating
                    reviews(first: 5, sort: NEWEST) {
                      summary { total_count }
                      reviews { rating }
                    }
                  }
                }
            GQL,
            'variables' => ['slug' => $series->slug],
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.seriesOverview.reviews.summary.total_count', 1)
            ->assertJsonMissingPath('errors');
    }

    private function createPublishedMovie(): Movie
    {
        return Movie::query()->create([
            'title' => 'Review Movie',
            'slug' => 'review-movie-'.uniqid(),
            'media_folder' => 'movies/review-movie',
            'publish_status' => 'published',
            'published_at' => now(),
            'metadata' => [],
        ]);
    }

    private function createAdminUser(): User
    {
        $this->seed(RolePermissionSeeder::class);

        $adminRole = Role::query()->where('name', 'admin')->firstOrFail();

        $user = User::factory()->create([
            'status' => 'active',
        ]);

        $user->roles()->attach($adminRole->id);

        return $user;
    }
}

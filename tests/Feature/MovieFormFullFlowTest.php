<?php

namespace Tests\Feature;

use App\Models\ImageAsset;
use App\Models\Movie;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * End-to-end: create shell → publication-form (images) → show → publish-status.
 */
class MovieFormFullFlowTest extends TestCase
{
    use RefreshDatabase;

    private string $imageRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->imageRoot = storage_path('framework/testing-image-root');
        File::ensureDirectoryExists($this->imageRoot);

        config([
            'cinema_images.storage_root' => $this->imageRoot,
            'cinema_images.public_base_url' => '',
            'cinema_images.public_prefix' => '/images',
        ]);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->imageRoot)) {
            File::deleteDirectory($this->imageRoot);
        }

        parent::tearDown();
    }

    public function test_publication_form_accepts_managed_image_paths(): void
    {
        $admin = $this->createAdminUser();
        Sanctum::actingAs($admin);

        $movie = Movie::query()->create([
            'title' => 'Managed Paths',
            'slug' => 'managed-paths',
            'media_folder' => 'movies/managed-paths',
            'publish_status' => 'draft',
            'processing_status' => 'pending',
        ]);

        $response = $this->putJson("/api/admin/movies/{$movie->id}/publication-form", [
            'title' => 'Managed Paths',
            'slug' => 'managed-paths',
            'poster_url' => '/images/2026/test-poster.jpg',
            'backdrop_url' => '/images/2026/test-backdrop.jpg',
            'actors' => [
                [
                    'name' => 'Test Actor',
                    'character' => 'Hero',
                    'profile_url' => '/images/2026/test-profile.jpg',
                ],
            ],
            'cast_members' => ['Test Actor'],
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
    }

    public function test_complete_movie_form_flow_with_image_ingestion(): void
    {
        $admin = $this->createAdminUser();
        Sanctum::actingAs($admin);

        $sourceUrl = 'https://cdn.example.com/sources/full-flow-source.mp4';

        // Step 1 — create movie shell
        $create = $this->postJson('/api/admin/movies', [
            'title' => 'Full Flow Movie',
            'original_title' => 'Full Flow Movie',
            'source_path' => $sourceUrl,
            'publish_status' => 'draft',
            'start_processing' => false,
        ]);

        $create->assertCreated();
        $movieId = $create->json('data.movie.id');
        $this->assertNotNull($movieId);

        Http::fake([
            'https://image.tmdb.org/*' => Http::response(
                $this->fakeJpegBinary(),
                200,
                ['Content-Type' => 'image/jpeg'],
            ),
            'http://cinema.af/*' => Http::response('', 200),
        ]);

        // Step 2 — publication form with all image fields
        $publication = $this->putJson("/api/admin/movies/{$movieId}/publication-form", [
            'title' => 'Full Flow Movie',
            'slug' => 'full-flow-movie',
            'poster_url' => 'https://image.tmdb.org/t/p/w780/full-flow-poster.jpg',
            'backdrop_url' => 'https://image.tmdb.org/t/p/original/full-flow-backdrop.jpg',
            'banner_url' => 'https://image.tmdb.org/t/p/original/full-flow-banner.jpg',
            'logo_url' => 'https://image.tmdb.org/t/p/w500/full-flow-logo.jpg',
            'trailer_url' => 'https://www.youtube.com/watch?v=test123',
            'synopsis' => 'Test synopsis',
            'age_rating' => 'PG-13',
            'release_year' => 2025,
            'tmdb_id' => 12345,
            'genres' => ['Action'],
            'actors' => [
                [
                    'name' => 'Test Actor',
                    'character' => 'Hero',
                    'profile_url' => 'https://image.tmdb.org/t/p/w300/test-actor.jpg',
                ],
            ],
            'cast_members' => ['Test Actor'],
        ]);

        $publication->assertOk();
        $publication->assertJsonPath('success', true);

        $movie = $publication->json('data.movie');
        $this->assertStringStartsWith('https://image.tmdb.org/', (string) data_get($movie, 'metadata.tmdb.poster_url'));
        $this->assertStringStartsWith('https://image.tmdb.org/', (string) data_get($movie, 'metadata.tmdb.backdrop_url'));
        $this->assertNull(data_get($movie, 'metadata.images.poster'));

        (new \App\Jobs\ProcessMovieImagesJob((int) $movieId))->handle(
            app(\App\Services\Cinema\ContentImageIngestionService::class),
        );

        $stored = Movie::query()->findOrFail($movieId);
        $this->assertStringStartsWith('/images/', (string) data_get($stored->metadata, 'tmdb.poster_url'));
        $this->assertStringStartsWith('/images/', (string) data_get($stored->metadata, 'tmdb.backdrop_url'));
        $this->assertNotNull(data_get($stored->metadata, 'images.poster'));
        $this->assertNotNull(data_get($stored->metadata, 'images.backdrop'));
        $this->assertStringStartsWith('https://image.tmdb.org/', (string) data_get($stored->metadata, 'tmdb.poster_source_url'));
        $this->assertStringStartsWith('https://image.tmdb.org/', (string) data_get($stored->metadata, 'tmdb.backdrop_source_url'));

        // Physical files exist on disk
        $assets = ImageAsset::query()->where('status', 'ready')->get();
        $this->assertGreaterThanOrEqual(1, $assets->count());
        foreach ($assets as $asset) {
            $this->assertFileExists((string) $asset->physical_storage_path);
        }

        // Step 3 — show (edit hydration)
        $show = $this->getJson("/api/admin/movies/{$movieId}");
        $show->assertOk();
        $show->assertJsonPath('data.movie.metadata.images.poster.original', data_get($stored->metadata, 'images.poster.original'));
        $show->assertJsonPath('data.movie.poster_url', data_get($stored->metadata, 'tmdb.poster_url'));

        // Step 4 — mark ready + publish (no HLS required when no hls_url)
        Movie::query()->whereKey($movieId)->update(['processing_status' => 'ready']);

        $publish = $this->putJson("/api/admin/movies/{$movieId}/publish-status", [
            'publish_status' => 'published',
        ]);

        $publish->assertOk();
        $publish->assertJsonPath('data.movie.publish_status', 'published');

        $this->assertDatabaseHas('movies', [
            'id' => $movieId,
            'publish_status' => 'published',
            'processing_status' => 'ready',
        ]);
    }

    private function createAdminUser(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $adminRole = Role::query()->where('slug', 'admin')->firstOrFail();
        $user = User::factory()->create();
        $user->roles()->attach($adminRole->id);

        return $user;
    }

    private function fakeJpegBinary(): string
    {
        return base64_decode(
            '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAb/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCdABmX/9k=',
            true,
        ) ?: '';
    }
}

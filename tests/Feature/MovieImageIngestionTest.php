<?php

namespace Tests\Feature;

use App\Models\ImageAsset;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MovieImageIngestionTest extends TestCase
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

    public function test_publication_form_ingests_movie_poster_and_backdrop_images(): void
    {
        $admin = $this->createAdminUser();
        Sanctum::actingAs($admin);

        $sourceUrl = 'https://cdn.example.com/sources/movie-image-source.mp4';

        $createResponse = $this->postJson('/api/admin/movies', [
            'original_title' => 'Image Test Movie',
            'source_path' => $sourceUrl,
            'start_processing' => false,
        ]);

        $movieId = $createResponse->json('data.movie.id');

        Http::fake([
            'https://image.tmdb.org/*' => Http::response(
                $this->fakeJpegBinary(),
                200,
                ['Content-Type' => 'image/jpeg'],
            ),
        ]);

        $response = $this->putJson("/api/admin/movies/{$movieId}/publication-form", [
            'title' => 'Image Test Movie',
            'slug' => 'image-test-movie',
            'poster_url' => 'https://image.tmdb.org/t/p/w780/test-poster.jpg',
            'backdrop_url' => 'https://image.tmdb.org/t/p/original/test-backdrop.jpg',
            'banner_url' => 'https://image.tmdb.org/t/p/original/test-banner.jpg',
            'logo_url' => 'https://image.tmdb.org/t/p/w500/test-logo.jpg',
            'synopsis' => 'Synopsis',
            'release_year' => 2024,
            'actors' => [
                [
                    'name' => 'Actor One',
                    'character' => 'Hero',
                    'profile_url' => 'https://image.tmdb.org/t/p/w300/actor-one.jpg',
                ],
            ],
        ]);

        $response->assertOk();

        $movie = $response->json('data.movie');
        $posterUrl = data_get($movie, 'metadata.tmdb.poster_url');
        $backdropUrl = data_get($movie, 'metadata.tmdb.backdrop_url');

        $this->assertStringStartsWith('/images/', (string) $posterUrl);
        $this->assertStringStartsWith('/images/', (string) $backdropUrl);
        $this->assertNotNull(data_get($movie, 'metadata.images.poster.original'));
        $this->assertNotNull(data_get($movie, 'metadata.images.backdrop.original'));
        $this->assertStringContainsString('width=384', (string) data_get($movie, 'metadata.images.poster.src'));

        $this->assertGreaterThanOrEqual(1, ImageAsset::query()->where('status', 'ready')->count());
        $this->assertDatabaseHas('image_assets', [
            'entity_type' => 'movie',
            'entity_id' => $movieId,
            'image_type' => 'poster',
            'status' => 'ready',
        ]);
        $this->assertStringStartsWith('/images/', (string) data_get($movie, 'metadata.tmdb.banner_url'));
        $this->assertStringStartsWith('/images/', (string) data_get($movie, 'metadata.tmdb.logo_url'));
        $this->assertNotNull(data_get($movie, 'metadata.images.banner'));
        $this->assertNotNull(data_get($movie, 'metadata.images.logo'));
        $this->assertNotNull(data_get($movie, 'poster_image.original'));
        $this->assertNotNull(data_get($movie, 'backdrop_image.original'));
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

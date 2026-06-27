<?php

namespace Tests\Feature;

use App\Models\Movie;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TmdbPopularityPersistenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cinema.media_root', storage_path('framework/testing-media-root'));
    }

    public function test_publication_form_persists_popularity_and_vote_count(): void
    {
        $admin = $this->createAdminUser();
        $sourcePath = $this->createReadableMediaFile('popularity-source.mp4');

        Sanctum::actingAs($admin);

        $createResponse = $this->postJson('/api/admin/movies', [
            'original_title' => 'Popularity Draft',
            'source_path' => $sourcePath,
            'start_processing' => false,
        ]);

        $movieId = $createResponse->json('data.movie.id');

        $this->putJson("/api/admin/movies/{$movieId}/publication-form", [
            'title' => 'Popularity Movie',
            'slug' => 'popularity-movie',
            'tmdb_id' => 550,
            'rating' => 8.4,
            'popularity' => 91.234,
            'vote_count' => 28000,
            'genres' => ['Drama'],
        ])->assertOk();

        $movie = Movie::query()->findOrFail($movieId);

        $this->assertSame(91.234, (float) $movie->filter_popularity);
        $this->assertSame(28000, (int) $movie->vote_count);
        $this->assertSame(91.234, (float) data_get($movie->metadata, 'tmdb.popularity'));
        $this->assertSame(28000, (int) data_get($movie->metadata, 'tmdb.vote_count'));
    }

    private function createAdminUser(): User
    {
        $this->seed(RolePermissionSeeder::class);

        $user = User::factory()->create([
            'status' => 'active',
        ]);

        $adminRole = Role::query()->where('slug', 'admin')->firstOrFail();
        $user->roles()->sync([$adminRole->id]);

        return $user;
    }

    private function createReadableMediaFile(string $name): string
    {
        $directory = storage_path('framework/testing-media');
        File::ensureDirectoryExists($directory);

        $path = $directory.DIRECTORY_SEPARATOR.$name;
        File::put($path, 'fake-video-binary');

        return $path;
    }
}

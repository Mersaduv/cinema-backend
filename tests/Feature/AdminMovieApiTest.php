<?php

namespace Tests\Feature;

use App\Jobs\ProcessMovieMediaAssetJob;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminMovieApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_register_a_movie_with_a_media_source(): void
    {
        $admin = $this->createAdminUser();
        $sourcePath = $this->createReadableMediaFile('dune-source.mp4');
        $normalizedSourcePath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $sourcePath);

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/admin/movies', [
            'title' => 'Dune Part Two',
            'source_path' => $sourcePath,
            'publish_status' => 'draft',
            'start_processing' => false,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.movie.title', 'Dune Part Two')
            ->assertJsonPath('data.movie.media_asset.source_path', $normalizedSourcePath);

        $this->assertDatabaseHas('movies', [
            'title' => 'Dune Part Two',
            'slug' => 'dune-part-two',
            'processing_status' => 'pending',
        ]);

        $this->assertDatabaseHas('media_assets', [
            'source_path' => $normalizedSourcePath,
            'processing_status' => 'pending',
        ]);
    }

    public function test_admin_can_queue_movie_processing(): void
    {
        Queue::fake();

        $admin = $this->createAdminUser();
        $sourcePath = $this->createReadableMediaFile('arrival-source.mp4');

        Sanctum::actingAs($admin);

        $createResponse = $this->postJson('/api/admin/movies', [
            'title' => 'Arrival',
            'source_path' => $sourcePath,
            'publish_status' => 'draft',
            'start_processing' => false,
        ]);

        $movieId = $createResponse->json('data.movie.id');

        $response = $this->postJson("/api/admin/movies/{$movieId}/process");

        $response
            ->assertStatus(202)
            ->assertJsonPath('data.movie.processing_status', 'queued');

        Queue::assertPushed(ProcessMovieMediaAssetJob::class);
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

        $path = $directory . DIRECTORY_SEPARATOR . $name;
        File::put($path, 'fake-video-binary');

        return $path;
    }
}

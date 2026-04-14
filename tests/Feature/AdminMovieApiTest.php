<?php

namespace Tests\Feature;

use App\Jobs\ProcessMovieMediaAssetJob;
use App\Models\Country;
use App\Models\Genre;
use App\Models\Language;
use App\Models\Actor;
use App\Models\Role;
use App\Models\Tag;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminMovieApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cinema.media_root', storage_path('framework/testing-media-root'));
    }

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

    public function test_admin_can_register_movie_with_taxonomies(): void
    {
        $admin = $this->createAdminUser();
        $sourcePath = $this->createReadableMediaFile('taxonomy-source.mp4');
        $genre = Genre::query()->create(['name' => 'Action', 'slug' => 'action']);
        $country = Country::query()->create(['name' => 'USA', 'slug' => 'usa']);
        $language = Language::query()->create(['name' => 'English', 'slug' => 'english']);
        $tag = Tag::query()->create(['name' => 'IMAX', 'slug' => 'imax']);
        $actor = Actor::query()->create(['name' => 'Zendaya', 'slug' => 'zendaya']);

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/admin/movies', [
            'title' => 'Taxonomy Movie',
            'source_path' => $sourcePath,
            'publish_status' => 'draft',
            'genre_ids' => [$genre->id],
            'country_ids' => [$country->id],
            'language_ids' => [$language->id],
            'tag_ids' => [$tag->id],
            'actor_ids' => [$actor->id],
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.movie.taxonomies.genres.0.slug', 'action')
            ->assertJsonPath('data.movie.taxonomies.countries.0.slug', 'usa')
            ->assertJsonPath('data.movie.taxonomies.languages.0.slug', 'english')
            ->assertJsonPath('data.movie.taxonomies.tags.0.slug', 'imax')
            ->assertJsonPath('data.movie.taxonomies.actors.0.slug', 'zendaya');

        $this->assertDatabaseHas('genre_movie', [
            'genre_id' => $genre->id,
        ]);

        $this->assertDatabaseHas('country_movie', [
            'country_id' => $country->id,
        ]);

        $this->assertDatabaseHas('language_movie', [
            'language_id' => $language->id,
        ]);

        $this->assertDatabaseHas('movie_tag', [
            'tag_id' => $tag->id,
        ]);
    }

    public function test_admin_can_save_movie_publication_form(): void
    {
        $admin = $this->createAdminUser();
        $sourcePath = $this->createReadableMediaFile('publication-source.mp4');
        $posterPath = $this->createReadableMediaFile('publication-poster.png');
        $trailerPath = $this->createReadableMediaFile('publication-trailer.mp4');
        $dubbedPath = $this->createReadableMediaFile('publication-dubbed.mka');
        $subtitlePath = $this->createReadableMediaFile('publication-subtitle.srt');
        $genre = Genre::query()->create(['name' => 'Drama', 'slug' => 'drama']);
        $country = Country::query()->create(['name' => 'France', 'slug' => 'france']);
        $language = Language::query()->create(['name' => 'French', 'slug' => 'french']);
        $tag = Tag::query()->create(['name' => 'Festival', 'slug' => 'festival']);
        $actor = Actor::query()->create(['name' => 'Actor One', 'slug' => 'actor-one']);

        Sanctum::actingAs($admin);

        $createResponse = $this->postJson('/api/admin/movies', [
            'original_title' => 'Original Draft Title',
            'source_path' => $sourcePath,
            'start_processing' => false,
        ]);

        $movieId = $createResponse->json('data.movie.id');

        $response = $this->putJson("/api/admin/movies/{$movieId}/publication-form", [
            'title' => 'Display Title',
            'poster_path' => $posterPath,
            'trailer_path' => $trailerPath,
            'has_dubbed_audio' => true,
            'dubbed_source_path' => $dubbedPath,
            'has_subtitle' => true,
            'subtitle_source_path' => $subtitlePath,
            'synopsis' => 'Publication synopsis',
            'age_rating' => 'PG-13',
            'genre_ids' => [$genre->id],
            'country_ids' => [$country->id],
            'language_ids' => [$language->id],
            'tag_ids' => [$tag->id],
            'actor_ids' => [$actor->id],
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.movie.title', 'Display Title')
            ->assertJsonPath('data.movie.age_rating', 'PG-13')
            ->assertJsonPath('data.movie.cast_members.0', 'Actor One')
            ->assertJsonPath('data.movie.has_dubbed_audio', true)
            ->assertJsonPath('data.movie.has_subtitle', true)
            ->assertJsonPath('data.movie.taxonomies.genres.0.slug', 'drama')
            ->assertJsonPath('data.movie.taxonomies.actors.0.slug', 'actor-one');

        $this->assertDatabaseHas('movies', [
            'id' => $movieId,
            'title' => 'Display Title',
            'age_rating' => 'PG-13',
        ]);

        $movie = \App\Models\Movie::query()->findOrFail($movieId);
        $managedMovieDirectory = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, storage_path('framework/testing-media-root' . DIRECTORY_SEPARATOR . 'movies' . DIRECTORY_SEPARATOR . $movie->slug));
        $posterPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) $movie->poster_path);
        $trailerPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) $movie->trailer_path);
        $dubbedSourcePath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) $movie->dubbed_source_path);
        $subtitleSourcePath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) $movie->subtitle_source_path);

        $this->assertStringStartsWith($managedMovieDirectory, $posterPath);
        $this->assertStringStartsWith($managedMovieDirectory, $trailerPath);
        $this->assertStringStartsWith($managedMovieDirectory, $dubbedSourcePath);
        $this->assertStringStartsWith($managedMovieDirectory, $subtitleSourcePath);
        $this->assertFileExists($posterPath);
        $this->assertFileExists($trailerPath);
        $this->assertFileExists($dubbedSourcePath);
        $this->assertFileExists($subtitleSourcePath);
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

    public function test_stopping_processing_clears_queued_jobs_for_same_media(): void
    {
        $admin = $this->createAdminUser();
        $sourcePath = $this->createReadableMediaFile('stop-source.mp4');

        Sanctum::actingAs($admin);

        $createResponse = $this->postJson('/api/admin/movies', [
            'title' => 'Stop Queue Movie',
            'source_path' => $sourcePath,
            'start_processing' => false,
        ]);

        $movieId = $createResponse->json('data.movie.id');
        $mediaAssetId = $createResponse->json('data.movie.media_asset.id');

        $job = new ProcessMovieMediaAssetJob($mediaAssetId, $admin->id);
        $serializedCommand = serialize(clone $job);

        DB::table('jobs')->insert([
            [
                'queue' => 'media-processing',
                'payload' => json_encode([
                    'uuid' => (string) \Illuminate\Support\Str::uuid(),
                    'displayName' => ProcessMovieMediaAssetJob::class,
                    'job' => 'Illuminate\Queue\CallQueuedHandler@call',
                    'maxTries' => null,
                    'maxExceptions' => null,
                    'failOnTimeout' => false,
                    'backoff' => null,
                    'timeout' => null,
                    'retryUntil' => null,
                    'data' => [
                        'commandName' => ProcessMovieMediaAssetJob::class,
                        'command' => $serializedCommand,
                        'batchId' => null,
                    ],
                ], JSON_THROW_ON_ERROR),
                'attempts' => 0,
                'reserved_at' => null,
                'available_at' => now()->timestamp,
                'created_at' => now()->timestamp,
            ],
            [
                'queue' => 'media-processing',
                'payload' => json_encode([
                    'uuid' => (string) \Illuminate\Support\Str::uuid(),
                    'displayName' => ProcessMovieMediaAssetJob::class,
                    'job' => 'Illuminate\Queue\CallQueuedHandler@call',
                    'maxTries' => null,
                    'maxExceptions' => null,
                    'failOnTimeout' => false,
                    'backoff' => null,
                    'timeout' => null,
                    'retryUntil' => null,
                    'data' => [
                        'commandName' => ProcessMovieMediaAssetJob::class,
                        'command' => $serializedCommand,
                        'batchId' => null,
                    ],
                ], JSON_THROW_ON_ERROR),
                'attempts' => 0,
                'reserved_at' => null,
                'available_at' => now()->timestamp,
                'created_at' => now()->timestamp,
            ],
        ]);

        $this->assertGreaterThan(
            0,
            DB::table('jobs')
                ->where('queue', 'media-processing')
                ->where('payload', 'like', '%ProcessMovieMediaAssetJob%')
                ->where('payload', 'like', '%mediaAssetId%')
                ->where('payload', 'like', '%' . $mediaAssetId . '%')
                ->count()
        );

        \App\Models\Movie::query()->findOrFail($movieId)->update([
            'processing_status' => 'queued',
        ]);
        \App\Models\Movie::query()->findOrFail($movieId)->mediaAsset()->update([
            'processing_status' => 'queued',
        ]);

        $response = $this->postJson("/api/admin/movies/{$movieId}/stop-processing");

        $response
            ->assertOk()
            ->assertJsonPath('data.movie.processing_status', 'pending');

        $this->assertSame(
            0,
            DB::table('jobs')
                ->where('queue', 'media-processing')
                ->where('payload', 'like', '%ProcessMovieMediaAssetJob%')
                ->where('payload', 'like', '%mediaAssetId%')
                ->where('payload', 'like', '%' . $mediaAssetId . '%')
                ->count()
        );
    }

    public function test_deleting_movie_disposes_queued_processing_for_same_media(): void
    {
        $admin = $this->createAdminUser();
        $sourcePath = $this->createReadableMediaFile('delete-dispose-source.mp4');

        Sanctum::actingAs($admin);

        $createResponse = $this->postJson('/api/admin/movies', [
            'title' => 'Delete Dispose Movie',
            'source_path' => $sourcePath,
            'start_processing' => false,
        ]);

        $movieId = $createResponse->json('data.movie.id');
        $mediaAssetId = $createResponse->json('data.movie.media_asset.id');

        $job = new ProcessMovieMediaAssetJob($mediaAssetId, $admin->id);
        $serializedCommand = serialize(clone $job);

        DB::table('jobs')->insert([
            'queue' => 'media-processing',
            'payload' => json_encode([
                'uuid' => (string) \Illuminate\Support\Str::uuid(),
                'displayName' => ProcessMovieMediaAssetJob::class,
                'job' => 'Illuminate\Queue\CallQueuedHandler@call',
                'maxTries' => null,
                'maxExceptions' => null,
                'failOnTimeout' => false,
                'backoff' => null,
                'timeout' => null,
                'retryUntil' => null,
                'data' => [
                    'commandName' => ProcessMovieMediaAssetJob::class,
                    'command' => $serializedCommand,
                    'batchId' => null,
                ],
            ], JSON_THROW_ON_ERROR),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ]);

        \App\Models\Movie::query()->findOrFail($movieId)->update([
            'processing_status' => 'queued',
        ]);
        \App\Models\Movie::query()->findOrFail($movieId)->mediaAsset()->update([
            'processing_status' => 'queued',
        ]);

        $response = $this->deleteJson("/api/admin/movies/{$movieId}");

        $response->assertOk();

        $this->assertDatabaseMissing('movies', [
            'id' => $movieId,
        ]);

        $this->assertSame(
            0,
            DB::table('jobs')
                ->where('queue', 'media-processing')
                ->where('payload', 'like', '%ProcessMovieMediaAssetJob%')
                ->where('payload', 'like', '%mediaAssetId%')
                ->where('payload', 'like', '%' . $mediaAssetId . '%')
                ->count()
        );
    }

    public function test_admin_can_update_movie_draft_form(): void
    {
        $admin = $this->createAdminUser();
        $sourcePath = $this->createReadableMediaFile('draft-update-source.mp4');
        $updatedSourcePath = $this->createReadableMediaFile('draft-update-source-2.mp4');
        $genre = Genre::query()->create(['name' => 'Sci-Fi', 'slug' => 'sci-fi']);
        $actor = Actor::query()->create(['name' => 'Timothee Chalamet', 'slug' => 'timothee-chalamet']);

        Sanctum::actingAs($admin);

        $createResponse = $this->postJson('/api/admin/movies', [
            'original_title' => 'Draft Original',
            'source_path' => $sourcePath,
            'start_processing' => false,
        ]);

        $movieId = $createResponse->json('data.movie.id');

        $response = $this->putJson("/api/admin/movies/{$movieId}/draft-form", [
            'title' => 'Draft Display',
            'original_title' => 'Draft Original Updated',
            'slug' => 'draft-display-updated',
            'release_year' => 2024,
            'provider_name' => 'Cinema Provider',
            'source_path' => $updatedSourcePath,
            'genre_ids' => [$genre->id],
            'actor_ids' => [$actor->id],
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.movie.slug', 'draft-display-updated')
            ->assertJsonPath('data.movie.provider_name', 'Cinema Provider')
            ->assertJsonPath('data.movie.taxonomies.actors.0.slug', 'timothee-chalamet');

        $this->assertDatabaseHas('movies', [
            'id' => $movieId,
            'slug' => 'draft-display-updated',
            'provider_name' => 'Cinema Provider',
        ]);
    }

    public function test_ready_movie_can_be_published_by_admin(): void
    {
        $admin = $this->createAdminUser();
        $sourcePath = $this->createReadableMediaFile('published-source.mp4');

        Sanctum::actingAs($admin);

        $createResponse = $this->postJson('/api/admin/movies', [
            'title' => 'Published Movie',
            'source_path' => $sourcePath,
            'publish_status' => 'draft',
            'start_processing' => false,
        ]);

        $movieId = $createResponse->json('data.movie.id');

        $movie = \App\Models\Movie::query()->findOrFail($movieId);
        $movie->update(['processing_status' => 'ready']);
        $movie->mediaAsset()->update([
            'processing_status' => 'ready',
        ]);

        $response = $this->putJson("/api/admin/movies/{$movieId}/publish-status", [
            'publish_status' => 'published',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.movie.publish_status', 'published');

        $this->assertDatabaseHas('movies', [
            'id' => $movieId,
            'publish_status' => 'published',
        ]);
    }

    public function test_admin_can_register_a_movie_with_uploaded_source_file(): void
    {
        $admin = $this->createAdminUser();

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/admin/movies', [
            'title' => 'Uploaded Movie',
            'publish_status' => 'draft',
            'start_processing' => false,
            'source_file' => UploadedFile::fake()->create('uploaded-movie.mp4', 256, 'video/mp4'),
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.movie.title', 'Uploaded Movie');

        $storedSourcePath = $response->json('data.movie.media_asset.source_path');

        $this->assertNotNull($storedSourcePath);
        $this->assertStringContainsString('testing-media-root', $storedSourcePath);
        $this->assertStringEndsWith('source.mp4', strtolower($storedSourcePath));
        $this->assertFileExists($storedSourcePath);
    }

    public function test_admin_can_browse_available_media_files(): void
    {
        $admin = $this->createAdminUser();
        $sourcePath = $this->createReadableMediaFile('browser-test.mp4');

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/admin/media-browser/entries?kind=video&path=' . urlencode(dirname($sourcePath)));

        $response
            ->assertOk()
            ->assertJsonPath('data.current_path', str_replace(['/', '\\'], DIRECTORY_SEPARATOR, dirname($sourcePath)))
            ->assertJsonFragment([
                'name' => 'browser-test.mp4',
                'path' => str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $sourcePath),
                'extension' => 'mp4',
                'is_supported' => true,
            ]);
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

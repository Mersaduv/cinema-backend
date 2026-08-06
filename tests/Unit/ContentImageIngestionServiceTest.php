<?php

namespace Tests\Unit;

use App\Models\Movie;
use App\Services\Cinema\ContentImageIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ContentImageIngestionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_process_movie_publication_stores_source_urls(): void
    {
        $imageRoot = storage_path('framework/testing-image-root-unit');
        File::ensureDirectoryExists($imageRoot);
        config(['cinema_images.storage_root' => $imageRoot]);

        Http::fake([
            'https://image.tmdb.org/*' => Http::response(
                base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAb/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCdABmX/9k=', true) ?: '',
                200,
                ['Content-Type' => 'image/jpeg'],
            ),
        ]);

        $movie = Movie::query()->create([
            'title' => 'Unit Test',
            'slug' => 'unit-test',
            'media_folder' => 'movies/unit-test',
            'publish_status' => 'draft',
            'processing_status' => 'pending',
        ]);

        $service = app(ContentImageIngestionService::class);
        $result = $service->processMoviePublication($movie, [
            'poster_url' => 'https://image.tmdb.org/t/p/w780/poster.jpg',
            'backdrop_url' => 'https://image.tmdb.org/t/p/original/backdrop.jpg',
        ], [
            'tmdb' => [
                'poster_url' => 'https://image.tmdb.org/t/p/w780/poster.jpg',
                'backdrop_url' => 'https://image.tmdb.org/t/p/original/backdrop.jpg',
            ],
        ]);

        $this->assertStringStartsWith('https://image.tmdb.org/', (string) data_get($result['metadata'], 'tmdb.poster_source_url'));
        $this->assertStringStartsWith('/images/', (string) data_get($result['metadata'], 'tmdb.poster_url'));

        File::deleteDirectory($imageRoot);
    }

    public function test_resolve_ingest_url_skips_already_processed_and_unchanged_source(): void
    {
        $service = app(ContentImageIngestionService::class);

        $this->assertNull(
            $service->resolveIngestUrl(
                '/images/2026/existing.jpg',
                '/images/2026/existing.jpg',
                'https://image.tmdb.org/t/p/w780/poster.jpg',
            ),
        );

        $this->assertNull(
            $service->resolveIngestUrl(
                'https://image.tmdb.org/t/p/w780/poster.jpg',
                '/images/2026/existing.jpg',
                'https://image.tmdb.org/t/p/w780/poster.jpg',
            ),
        );

        $this->assertSame(
            'https://image.tmdb.org/t/p/w780/new-poster.jpg',
            $service->resolveIngestUrl(
                'https://image.tmdb.org/t/p/w780/new-poster.jpg',
                '/images/2026/existing.jpg',
                'https://image.tmdb.org/t/p/w780/poster.jpg',
            ),
        );
    }

    public function test_process_movie_publication_defers_poster_ingest_for_queue(): void
    {
        $imageRoot = storage_path('framework/testing-image-root-unit-defer');
        File::ensureDirectoryExists($imageRoot);
        config(['cinema_images.storage_root' => $imageRoot]);

        Http::fake([
            'https://image.tmdb.org/*' => Http::response(
                base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAb/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCdABmX/9k=', true) ?: '',
                200,
                ['Content-Type' => 'image/jpeg'],
            ),
        ]);

        $movie = Movie::query()->create([
            'title' => 'Defer Test',
            'slug' => 'defer-test',
            'media_folder' => 'movies/defer-test',
            'publish_status' => 'draft',
            'processing_status' => 'pending',
        ]);

        $service = app(ContentImageIngestionService::class);
        $tmdbPoster = 'https://image.tmdb.org/t/p/w780/poster.jpg';

        $first = $service->processMoviePublication($movie, [
            'poster_url' => $tmdbPoster,
        ], [
            'tmdb' => ['poster_url' => $tmdbPoster],
        ], deferSecondaryImages: true);

        $this->assertSame($tmdbPoster, data_get($first['metadata'], 'tmdb.poster_url'));
        $this->assertSame($tmdbPoster, data_get($first['metadata'], 'tmdb.poster_source_url'));
        $this->assertNull(data_get($first['metadata'], 'images.poster'));

        $assetCountAfterDefer = \App\Models\ImageAsset::query()->count();

        $movie->update(['metadata' => $first['metadata']]);
        $processed = $service->processMovieDeferredSecondaryImages($movie->fresh());

        $this->assertTrue($processed['poster']);
        $this->assertStringStartsWith('/images/', (string) data_get($movie->fresh()->metadata, 'tmdb.poster_url'));
        $this->assertGreaterThan($assetCountAfterDefer, \App\Models\ImageAsset::query()->count());

        File::deleteDirectory($imageRoot);
    }

    public function test_process_movie_publication_does_not_reingest_unchanged_tmdb_source(): void
    {
        $imageRoot = storage_path('framework/testing-image-root-unit-dedup');
        File::ensureDirectoryExists($imageRoot);
        config(['cinema_images.storage_root' => $imageRoot]);

        Http::fake([
            'https://image.tmdb.org/*' => Http::response(
                base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAb/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCdABmX/9k=', true) ?: '',
                200,
                ['Content-Type' => 'image/jpeg'],
            ),
        ]);

        $movie = Movie::query()->create([
            'title' => 'Dedup Test',
            'slug' => 'dedup-test',
            'media_folder' => 'movies/dedup-test',
            'publish_status' => 'draft',
            'processing_status' => 'pending',
        ]);

        $service = app(ContentImageIngestionService::class);
        $tmdbPoster = 'https://image.tmdb.org/t/p/w780/poster.jpg';

        $first = $service->processMoviePublication($movie, [
            'poster_url' => $tmdbPoster,
        ], [
            'tmdb' => ['poster_url' => $tmdbPoster],
        ]);

        $assetCountAfterFirst = \App\Models\ImageAsset::query()->count();

        $second = $service->processMoviePublication($movie, [
            'poster_url' => $tmdbPoster,
        ], $first['metadata']);

        $this->assertSame($assetCountAfterFirst, \App\Models\ImageAsset::query()->count());
        $this->assertSame(
            data_get($first['metadata'], 'tmdb.poster_url'),
            data_get($second['metadata'], 'tmdb.poster_url'),
        );

        File::deleteDirectory($imageRoot);
    }

    public function test_ingest_tmdb_sync_batch_processes_primary_and_actor_images(): void
    {
        $imageRoot = storage_path('framework/testing-image-root-unit-tmdb-sync');
        File::ensureDirectoryExists($imageRoot);
        config([
            'cinema_images.storage_root' => $imageRoot,
            'cinema_images.public_base_url' => 'http://cinema.af',
            'cinema_images.public_prefix' => '/images',
        ]);

        Http::fake([
            'https://image.tmdb.org/*' => Http::response(
                base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAb/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCdABmX/9k=', true) ?: '',
                200,
                ['Content-Type' => 'image/jpeg'],
            ),
        ]);

        $service = app(ContentImageIngestionService::class);
        $result = $service->ingestTmdbSyncBatch([
            'entity_type' => 'movie',
            'entity_id' => null,
            'poster_url' => 'https://image.tmdb.org/t/p/w780/poster.jpg',
            'backdrop_url' => 'https://image.tmdb.org/t/p/original/backdrop.jpg',
            'actors' => [
                ['name' => 'Actor One', 'profile_url' => 'https://image.tmdb.org/t/p/w185/actor.jpg'],
            ],
        ]);

        $this->assertStringStartsWith('/images/', (string) $result['poster_url']);
        $this->assertStringStartsWith('/images/', (string) $result['backdrop_url']);
        $this->assertStringStartsWith('/images/', (string) $result['actors'][0]['profile_url']);
        $this->assertStringNotContainsString('http://', (string) $result['poster_url']);
        $this->assertGreaterThanOrEqual(3, (int) data_get($result, 'stats.succeeded'));

        File::deleteDirectory($imageRoot);
    }
}

<?php

namespace Tests\Unit;

use App\Models\ImageAsset;
use App\Services\Cinema\ImageIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ImageIngestionServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $tempRoot;

    private ImageIngestionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cinema-ingest-test-' . uniqid('', true);
        File::ensureDirectoryExists($this->tempRoot);

        config([
            'cinema_images.storage_root' => $this->tempRoot,
            'cinema_images.public_base_url' => '',
            'cinema_images.public_prefix' => '/images',
            'cinema_images.tmdb_image_base_url' => 'https://image.tmdb.org/t/p',
        ]);

        $this->service = app(ImageIngestionService::class);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempRoot)) {
            File::deleteDirectory($this->tempRoot);
        }

        parent::tearDown();
    }

    public function test_movie_poster_ingestion(): void
    {
        Http::fake([
            'https://example.test/poster.jpg' => Http::response(
                $this->fakeJpegBinary(),
                200,
                ['Content-Type' => 'image/jpeg'],
            ),
        ]);

        $result = $this->service->ingest('https://example.test/poster.jpg', [
            'entity_type' => 'movie',
            'entity_id' => 1,
            'image_type' => 'poster',
            'is_primary' => true,
        ]);

        $this->assertTrue($result['success']);
        $this->assertNotNull($result['processed_url']);
        $this->assertStringStartsWith('/images/', (string) $result['processed_url']);
        $this->assertDatabaseHas('image_assets', [
            'entity_type' => 'movie',
            'image_type' => 'poster',
            'status' => 'ready',
        ]);
    }

    public function test_duplicate_source_url_reuse(): void
    {
        Http::fake([
            'https://example.test/shared.jpg' => Http::response(
                $this->fakeJpegBinary(),
                200,
                ['Content-Type' => 'image/jpeg'],
            ),
        ]);

        $first = $this->service->ingest('https://example.test/shared.jpg', [
            'entity_type' => 'movie',
            'entity_id' => 1,
            'image_type' => 'poster',
        ]);

        $second = $this->service->ingest('https://example.test/shared.jpg', [
            'entity_type' => 'series',
            'entity_id' => 2,
            'image_type' => 'poster',
        ]);

        $this->assertTrue($first['success']);
        $this->assertTrue($second['success']);
        $this->assertSame($first['processed_url'], $second['processed_url']);
        $this->assertSame(1, ImageAsset::query()->where('status', 'ready')->count());
        Http::assertSentCount(1);
    }

    public function test_ingest_many_downloads_in_parallel(): void
    {
        Http::fake([
            'https://example.test/poster.jpg' => Http::response(
                $this->fakeJpegBinary(),
                200,
                ['Content-Type' => 'image/jpeg'],
            ),
            'https://example.test/backdrop.jpg' => Http::response(
                $this->fakeJpegBinary(),
                200,
                ['Content-Type' => 'image/jpeg'],
            ),
        ]);

        $results = $this->service->ingestMany([
            'poster' => [
                'source_url' => 'https://example.test/poster.jpg',
                'context' => [
                    'entity_type' => 'movie',
                    'entity_id' => 10,
                    'image_type' => 'poster',
                    'is_primary' => true,
                ],
            ],
            'backdrop' => [
                'source_url' => 'https://example.test/backdrop.jpg',
                'context' => [
                    'entity_type' => 'movie',
                    'entity_id' => 10,
                    'image_type' => 'backdrop',
                ],
            ],
        ]);

        $this->assertTrue($results['poster']['success']);
        $this->assertTrue($results['backdrop']['success']);
        $this->assertStringStartsWith('/images/', (string) $results['poster']['processed_url']);
        $this->assertStringStartsWith('/images/', (string) $results['backdrop']['processed_url']);
        Http::assertSentCount(2);
    }

    public function test_failed_download_fallback(): void
    {
        Http::fake([
            'https://example.test/missing.jpg' => Http::response('', 404),
        ]);

        $result = $this->service->ingest('https://example.test/missing.jpg', [
            'entity_type' => 'movie',
            'entity_id' => 3,
            'image_type' => 'backdrop',
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('https://example.test/missing.jpg', $result['fallback_url']);
        $this->assertDatabaseHas('image_assets', [
            'entity_type' => 'movie',
            'image_type' => 'backdrop',
            'status' => 'failed',
        ]);
    }

    private function fakeJpegBinary(): string
    {
        return base64_decode(
            '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAb/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCdABmX/9k=',
            true,
        ) ?: '';
    }
}

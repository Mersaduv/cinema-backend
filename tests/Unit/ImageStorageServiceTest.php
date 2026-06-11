<?php

namespace Tests\Unit;

use App\Services\Cinema\ImageStorageService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ImageStorageServiceTest extends TestCase
{
    private string $tempRoot;

    private ImageStorageService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cinema-image-test-' . uniqid('', true);
        File::ensureDirectoryExists($this->tempRoot);

        config([
            'cinema_images.storage_root' => $this->tempRoot,
            'cinema_images.public_prefix' => '/images',
            'cinema_images.collection_folder' => 'collections',
            'cinema_images.genre_folder' => 'genres',
        ]);

        $this->service = app(ImageStorageService::class);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempRoot)) {
            File::deleteDirectory($this->tempRoot);
        }

        parent::tearDown();
    }

    public function test_storage_root_resolution(): void
    {
        $this->assertSame($this->tempRoot, $this->service->storageRoot());
    }

    public function test_year_folder_image_path_generation(): void
    {
        $plan = $this->service->buildStoragePlan('year', 'jpg');

        $this->assertSame('year', $plan['folder_type']);
        $this->assertMatchesRegularExpression('#^/images/\d{4}/\d+-\d+\.jpg$#', $plan['public_path']);
        $this->assertStringStartsWith($this->tempRoot, $plan['physical_path']);
        $this->assertStringEndsWith('.jpg', $plan['physical_path']);
    }

    public function test_collection_image_path_generation(): void
    {
        $plan = $this->service->buildStoragePlan('collections', 'jpg');

        $this->assertSame('collections', $plan['folder_type']);
        $this->assertMatchesRegularExpression('#^/images/collections/\d+-\d+\.jpg$#', $plan['public_path']);
    }

    public function test_genre_svg_path_generation(): void
    {
        $plan = $this->service->buildStoragePlan('genres', 'svg');

        $this->assertSame('genres', $plan['folder_type']);
        $this->assertMatchesRegularExpression('#^/images/genres/\d+-\d+\.svg$#', $plan['public_path']);
    }

    public function test_save_binary_prevents_path_traversal(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->saveBinary($this->tempRoot . '/../outside.jpg', 'test');
    }
}

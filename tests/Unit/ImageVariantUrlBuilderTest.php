<?php

namespace Tests\Unit;

use App\Services\Cinema\ImageVariantUrlBuilder;
use Tests\TestCase;

class ImageVariantUrlBuilderTest extends TestCase
{
    private ImageVariantUrlBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = app(ImageVariantUrlBuilder::class);
    }

    public function test_relative_public_url_generation(): void
    {
        config(['cinema_images.public_base_url' => '']);

        $publicPath = '/images/2026/1780746758632-927388401.jpg';
        $image = $this->builder->buildFromPublicPath($publicPath, 'image/jpeg', 1920, 1080);

        $this->assertSame($publicPath, $image['original']);
        $this->assertStringContainsString('width=20&quality=10&sharpen=0', (string) $image['blur']);
        $this->assertStringContainsString('width=384&quality=65&sharpen=60', (string) $image['src']);
        $this->assertCount(10, $image['srcset']);
        $this->assertSame(256, $image['srcset'][0]['width']);
        $this->assertSame(3840, $image['srcset'][9]['width']);
        $this->assertStringContainsString('3840w', (string) $image['srcset_string']);
    }

    public function test_absolute_public_url_generation(): void
    {
        config(['cinema_images.public_base_url' => 'https://cdn.example.test']);

        $image = $this->builder->buildFromPublicPath('/images/2026/sample-123.jpg', 'image/jpeg');

        $this->assertSame(
            'https://cdn.example.test/images/2026/sample-123.jpg',
            $image['original'],
        );
        $this->assertStringStartsWith(
            'https://cdn.example.test/images/2026/sample-123.jpg?width=256',
            $image['srcset'][0]['url'],
        );
    }

    public function test_blur_placeholder_generation(): void
    {
        config(['cinema_images.public_base_url' => '']);

        $image = $this->builder->buildFromPublicPath('/images/2026/sample.jpg', 'image/jpeg');

        $this->assertSame(
            '/images/2026/sample.jpg?width=20&quality=10&sharpen=0',
            $image['blur'],
        );
    }

    public function test_full_srcset_generation_from_256w_to_3840w(): void
    {
        config(['cinema_images.public_base_url' => '']);

        $image = $this->builder->buildFromPublicPath('/images/2026/sample.jpg', 'image/jpeg');
        $widths = array_map(fn (array $item) => $item['width'], $image['srcset']);

        $this->assertSame([256, 384, 640, 750, 828, 1080, 1200, 1920, 2048, 3840], $widths);
    }
}

<?php

namespace Tests\Unit;

use App\Services\Cinema\ImagePresentationService;
use Tests\TestCase;

class ImagePresentationServiceTest extends TestCase
{
    public function test_public_presenter_prefers_processed_url(): void
    {
        config(['cinema_images.public_base_url' => '']);

        $service = app(ImagePresentationService::class);
        $metadata = [
            'images' => [
                'poster' => [
                    'original' => '/images/2026/processed.jpg',
                    'blur' => '/images/2026/processed.jpg?width=20&quality=10&sharpen=0',
                    'src' => '/images/2026/processed.jpg?width=384&quality=65&sharpen=60',
                    'srcset_string' => '/images/2026/processed.jpg?width=384&quality=65&sharpen=60 384w',
                ],
            ],
            'tmdb' => [
                'poster_url' => 'https://image.tmdb.org/t/p/w780/old.jpg',
                'poster_source_url' => 'https://image.tmdb.org/t/p/w780/old.jpg',
            ],
        ];

        $this->assertSame('/images/2026/processed.jpg', $service->resolveMoviePosterUrl(null, $metadata));
        $this->assertSame('/images/2026/processed.jpg', $service->moviePosterImage($metadata)['original']);
    }
}

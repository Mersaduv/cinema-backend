<?php

namespace Tests\Unit;

use App\Support\ProgressiveVideoUrl;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProgressiveVideoUrlTest extends TestCase
{
    #[Test]
    public function it_accepts_mp4_urls_and_coerces_missing_scheme(): void
    {
        $this->assertSame(
            'https://cdn.example.com/movies/a.mp4',
            ProgressiveVideoUrl::normalize('https://cdn.example.com/movies/a.mp4'),
        );
        $this->assertSame(
            'https://cinema.af/content2/movies/a.mp4',
            ProgressiveVideoUrl::normalize('cinema.af/content2/movies/a.mp4'),
        );
        $this->assertNull(ProgressiveVideoUrl::normalize('https://cdn.example.com/movies/master.m3u8'));
        $this->assertFalse(ProgressiveVideoUrl::looksLike('not-a-url'));
    }
}

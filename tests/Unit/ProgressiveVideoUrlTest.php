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

    #[Test]
    public function it_accepts_mkv_urls_used_by_cdn_sources(): void
    {
        $this->assertSame(
            'http://cinema.af/content/New-Series/show/1080/episode.mkv',
            ProgressiveVideoUrl::normalize('http://cinema.af/content/New-Series/show/1080/episode.mkv'),
        );
        $this->assertTrue(
            ProgressiveVideoUrl::looksLike(
                'http://cinema.af/content/New-Series/Money.Heist.Korea.2022/1080/Money.Heist.Korea.2022.S01.E01.1080p-CINEMA.AF.MKV',
            ),
        );
    }
}

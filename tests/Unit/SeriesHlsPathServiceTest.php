<?php

namespace Tests\Unit;

use App\Services\Cinema\MediaPathService;
use App\Services\Cinema\SeriesHlsPathService;
use PHPUnit\Framework\TestCase;

class SeriesHlsPathServiceTest extends TestCase
{
    private SeriesHlsPathService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SeriesHlsPathService(new MediaPathService());
    }

    public function test_builds_episode_url_when_series_base_omits_http_scheme(): void
    {
        $seriesBase = 'cinema.af/content2/beta/2026-05-12/Fallout';

        $this->assertSame(
            'http://cinema.af/content2/beta/2026-05-12/Fallout/season-01/episode-01/master.m3u8',
            $this->service->buildEpisodeHlsUrlFromSeries($seriesBase, 1, 1),
        );
    }

    public function test_builds_episode_url_from_series_base(): void
    {
        $seriesBase = 'http://cinema.af/content2/beta/2026-05-12/Fallout';

        $this->assertSame(
            'http://cinema.af/content2/beta/2026-05-12/Fallout/season-01/episode-01/master.m3u8',
            $this->service->buildEpisodeHlsUrlFromSeries($seriesBase, 1, 1),
        );
    }

    public function test_parses_episode_master_url_into_hierarchy(): void
    {
        $parsed = $this->service->parseHlsPackagePath(
            'http://cinema.af/content2/beta/2026-05-12/Fallout/season-01/episode-01/master.m3u8',
        );

        $this->assertNotNull($parsed);
        $this->assertSame('episode', $parsed['level']);
        $this->assertSame('http://cinema.af/content2/beta/2026-05-12/Fallout', $parsed['series_base']);
        $this->assertSame('http://cinema.af/content2/beta/2026-05-12/Fallout/season-01', $parsed['season_base']);
        $this->assertSame(1, $parsed['season_number']);
        $this->assertSame(1, $parsed['episode_number']);
    }

    public function test_resolve_prefers_explicit_episode_url(): void
    {
        $resolved = $this->service->resolveEpisodeHlsUrl(
            'http://cinema.af/content2/beta/2026-05-12/Fallout',
            'http://cinema.af/content2/beta/2026-05-12/Fallout/season-01',
            'http://cdn.example/custom/master.m3u8',
            1,
            1,
        );

        $this->assertSame('episode', $resolved['source']);
        $this->assertSame('http://cdn.example/custom/master.m3u8', $resolved['hls_url']);
    }
}

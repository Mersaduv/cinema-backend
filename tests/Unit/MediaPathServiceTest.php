<?php

namespace Tests\Unit;

use App\Services\Cinema\MediaPathService;
use PHPUnit\Framework\TestCase;

class MediaPathServiceTest extends TestCase
{
    private MediaPathService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new MediaPathService();
    }

    public function test_coerces_hostname_style_path_to_http(): void
    {
        $this->assertTrue($this->service->isRemoteMediaReference('cinema.af/content2/beta/Fallout'));
        $this->assertSame(
            'http://cinema.af/content2/beta/2026-05-12/Fallout/season-01/episode-01/master.m3u8',
            $this->service->coerceRemoteMediaUrl(
                'cinema.af/content2/beta/2026-05-12/Fallout/season-01/episode-01/master.m3u8',
            ),
        );
    }

    public function test_does_not_treat_windows_paths_as_remote(): void
    {
        $this->assertFalse($this->service->isRemoteMediaReference('D:\\media\\movies\\test\\master.m3u8'));
        $this->assertSame(
            'D:\\media\\movies\\test\\master.m3u8',
            $this->service->coerceRemoteMediaUrl('D:\\media\\movies\\test\\master.m3u8'),
        );
    }
}

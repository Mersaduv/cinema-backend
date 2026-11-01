<?php

namespace Tests\Unit;

use App\Services\Cinema\MediaPathService;
use Tests\TestCase;

class MediaPathServiceTest extends TestCase
{
    public function test_resolve_local_content2_path_maps_public_url_under_configured_root(): void
    {
        config()->set('cinema.content2_storage_root', 'D:\\content2');

        $service = app(MediaPathService::class);
        $path = $service->resolveLocalContent2Path(
            'http://cinema.af/content2/movies/demo/hls/master.m3u8',
        );

        $this->assertSame(
            'd:\\content2\\movies\\demo\\hls\\master.m3u8',
            $path,
        );
    }

    public function test_outbound_media_request_rewrites_public_host_to_fetch_base(): void
    {
        config()->set('cinema.remote_media_fetch_base_url', 'http://127.0.0.1:8080');
        config()->set('cinema.remote_media_fetch_hosts', ['cinema.af', 'www.cinema.af']);

        $service = app(MediaPathService::class);
        $outbound = $service->outboundMediaRequest(
            'http://cinema.af/content2/movies/demo/hls/master.m3u8',
        );

        $this->assertSame(
            'http://127.0.0.1:8080/content2/movies/demo/hls/master.m3u8',
            $outbound['url'],
        );
        $this->assertSame('cinema.af', $outbound['headers']['Host']);
    }

    public function test_resolve_local_content_path_maps_content_prefix_to_d_root(): void
    {
        config()->set('cinema.content2_storage_root', 'E:\\content');
        config()->set('cinema.content_storage_root', 'D:\\content');

        $service = app(MediaPathService::class);

        $this->assertSame(
            'd:\\content\\movies-2026-hls\\2026-11-01\\demo\\master.m3u8',
            $service->resolveLocalContent2Path(
                'http://cinema.af/content/movies-2026-HLS/2026-11-01/demo/master.m3u8',
            ),
        );

        $this->assertSame(
            'e:\\content\\movies2\\2026-10-18\\demo\\master.m3u8',
            $service->resolveLocalContent2Path(
                'http://cinema.af/content2/movies2/2026-10-18/demo/master.m3u8',
            ),
        );
    }
}

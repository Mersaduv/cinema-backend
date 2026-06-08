<?php

namespace Tests\Unit;

use App\Jobs\EnrichHlsPlaybackMetadataJob;
use App\Jobs\ValidateHlsManifestJob;
use App\Models\Movie;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ValidateHlsManifestJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_head_success_marks_movie_ready_quickly(): void
    {
        Http::fake([
            'https://cdn.example.com/quick/master.m3u8' => Http::response('', 200),
        ]);

        $movie = $this->createMovie('quick-movie');

        $startedAt = microtime(true);

        (new ValidateHlsManifestJob(
            $movie->id,
            'https://cdn.example.com/quick/master.m3u8',
        ))->handle(
            app(\App\Services\Cinema\HlsPackageInsightService::class),
            app(\App\Services\Cinema\MediaPathService::class),
        );

        $elapsedMs = (microtime(true) - $startedAt) * 1000;

        $movie->refresh();

        $this->assertSame('ready', $movie->processing_status);
        $this->assertLessThan(500, $elapsedMs);
    }

    public function test_head_failure_marks_movie_failed(): void
    {
        Http::fake([
            'https://cdn.example.com/bad/master.m3u8' => Http::response('', 404),
        ]);

        $movie = $this->createMovie('bad-movie');

        (new ValidateHlsManifestJob(
            $movie->id,
            'https://cdn.example.com/bad/master.m3u8',
        ))->handle(
            app(\App\Services\Cinema\HlsPackageInsightService::class),
            app(\App\Services\Cinema\MediaPathService::class),
        );

        $movie->refresh();

        $this->assertSame('failed', $movie->processing_status);
        $this->assertNotNull($movie->processing_error);
    }

    public function test_successful_probe_dispatches_enrichment_job(): void
    {
        Queue::fake();

        Http::fake([
            'https://cdn.example.com/enrich/master.m3u8' => Http::response('', 200),
        ]);

        $movie = $this->createMovie('enrich-movie');

        (new ValidateHlsManifestJob(
            $movie->id,
            'https://cdn.example.com/enrich/master.m3u8',
        ))->handle(
            app(\App\Services\Cinema\HlsPackageInsightService::class),
            app(\App\Services\Cinema\MediaPathService::class),
        );

        Queue::assertPushed(EnrichHlsPlaybackMetadataJob::class, function (EnrichHlsPlaybackMetadataJob $job) use ($movie): bool {
            return $job->movieId === $movie->id;
        });
    }

    private function createMovie(string $slug): Movie
    {
        return Movie::query()->create([
            'title' => ucfirst(str_replace('-', ' ', $slug)),
            'slug' => $slug,
            'media_folder' => "movies/{$slug}",
            'publish_status' => 'draft',
            'processing_status' => 'queued',
            'metadata' => ['playback' => ['hls_url' => null]],
        ]);
    }
}

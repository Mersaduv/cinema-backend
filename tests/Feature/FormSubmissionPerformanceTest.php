<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FormSubmissionPerformanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cinema.media_root', storage_path('framework/testing-media-root'));
    }

    public function test_movie_publication_form_completes_with_bounded_queries(): void
    {
        $admin = $this->createAdminUser();
        Sanctum::actingAs($admin);

        $movieId = $this->postJson('/api/admin/movies', [
            'original_title' => 'Perf Movie',
            'publish_status' => 'draft',
        ])->json('data.movie.id');

        Http::fake([
            'https://cdn.example.com/perf/master.m3u8' => Http::response('', 200),
            'https://cdn.example.com/perf/metadata.json' => Http::response('', 404),
        ]);

        DB::enableQueryLog();
        $startedAt = microtime(true);

        $response = $this->putJson("/api/admin/movies/{$movieId}/publication-form", [
            'title' => 'Perf Movie',
            'slug' => 'perf-movie',
            'hls_url' => 'https://cdn.example.com/perf/master.m3u8',
            'age_rating' => 'PG-13',
        ]);

        $elapsedMs = (microtime(true) - $startedAt) * 1000;
        $queryCount = count(DB::getQueryLog());

        $response->assertOk()->assertJsonPath('data.movie.processing_status', 'queued');

        $this->assertLessThan(500, $elapsedMs, "Publication form took {$elapsedMs}ms");
        $this->assertLessThan(42, $queryCount, "Publication form ran {$queryCount} queries");

        $this->assertDatabaseHas('movies', [
            'id' => $movieId,
            'processing_status' => 'ready',
        ]);
    }

    public function test_series_store_with_fifty_episodes_completes_under_threshold(): void
    {
        $admin = $this->createAdminUser();
        Sanctum::actingAs($admin);

        $seasons = [];

        for ($season = 1; $season <= 4; $season++) {
            $episodes = [];

            for ($episode = 1; $episode <= 13; $episode++) {
                $episodes[] = [
                    'episode_number' => $episode,
                    'title' => "S{$season}E{$episode}",
                    'source_method' => 'hls',
                    'hls_verified' => false,
                ];
            }

            $seasons[] = [
                'season_number' => $season,
                'name' => "Season {$season}",
                'episodes' => $episodes,
            ];
        }

        DB::enableQueryLog();
        $startedAt = microtime(true);

        $response = $this->postJson('/api/admin/series', [
            'title' => 'Perf Series',
            'slug' => 'perf-series',
            'age_rating' => 'TV-14',
            'publish_status' => 'draft',
            'seasons' => $seasons,
        ]);

        $elapsedMs = (microtime(true) - $startedAt) * 1000;
        $queryCount = count(DB::getQueryLog());

        $response->assertCreated();

        $seriesId = (int) $response->json('data.series.id');

        $this->assertLessThan(1500, $elapsedMs, "Series store took {$elapsedMs}ms");
        $this->assertLessThan(115, $queryCount, "Series store ran {$queryCount} queries");
        $this->assertSame(52, DB::table('series_episodes')->where('series_id', $seriesId)->count());
    }

    public function test_series_stress_store_with_two_hundred_episodes(): void
    {
        $admin = $this->createAdminUser();
        Sanctum::actingAs($admin);

        $seasons = [];

        for ($season = 1; $season <= 10; $season++) {
            $episodes = [];

            for ($episode = 1; $episode <= 20; $episode++) {
                $episodes[] = [
                    'episode_number' => $episode,
                    'title' => "S{$season}E{$episode}",
                    'source_method' => 'hls',
                ];
            }

            $seasons[] = [
                'season_number' => $season,
                'episodes' => $episodes,
            ];
        }

        $startedAt = microtime(true);
        $peakBefore = memory_get_peak_usage(true);

        $response = $this->postJson('/api/admin/series', [
            'title' => 'Stress Series',
            'slug' => 'stress-series',
            'age_rating' => 'TV-MA',
            'publish_status' => 'draft',
            'seasons' => $seasons,
        ]);

        $elapsedMs = (microtime(true) - $startedAt) * 1000;
        $peakAfter = memory_get_peak_usage(true);

        $response->assertCreated();
        $seriesId = (int) $response->json('data.series.id');

        $this->assertLessThan(3000, $elapsedMs, "Stress series store took {$elapsedMs}ms");
        $this->assertSame(200, DB::table('series_episodes')->where('series_id', $seriesId)->count());
        $this->assertLessThan(128 * 1024 * 1024, max($peakBefore, $peakAfter));
    }

    private function createAdminUser(): User
    {
        $this->seed(RolePermissionSeeder::class);

        $user = User::factory()->create([
            'status' => 'active',
        ]);

        $adminRole = Role::query()->where('slug', 'admin')->firstOrFail();
        $user->roles()->sync([$adminRole->id]);

        return $user;
    }
}

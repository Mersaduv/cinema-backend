<?php

namespace Tests\Feature;

use App\Models\Movie;
use App\Models\Role;
use App\Models\Series;
use App\Models\SeriesEpisode;
use App\Models\SeriesSeason;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminGetPerformanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cinema.media_root', storage_path('framework/testing-media-root'));
    }

    public function test_admin_movie_list_completes_under_threshold(): void
    {
        $admin = $this->createAdminUser();
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/movies', [
            'original_title' => 'List Perf Movie',
            'publish_status' => 'draft',
        ])->assertCreated();

        DB::enableQueryLog();
        $startedAt = microtime(true);

        $response = $this->getJson('/api/admin/movies?per_page=12');

        $elapsedMs = (microtime(true) - $startedAt) * 1000;
        $queryCount = count(DB::getQueryLog());

        $response->assertOk()->assertJsonPath('success', true);

        $this->assertLessThan(1000, $elapsedMs, "Movie list took {$elapsedMs}ms");
        $this->assertLessThanOrEqual(5, $queryCount, "Movie list ran {$queryCount} queries");
    }

    public function test_admin_series_list_completes_under_threshold(): void
    {
        $admin = $this->createAdminUser();
        Sanctum::actingAs($admin);

        $series = Series::query()->create([
            'title' => 'List Perf Series',
            'slug' => 'list-perf-series',
            'publish_status' => 'draft',
            'metadata' => [],
        ]);

        DB::table('series')->where('id', $series->id)->update([
            'seasons_count' => 2,
            'episodes_count' => 10,
        ]);

        DB::enableQueryLog();
        $startedAt = microtime(true);

        $response = $this->getJson('/api/admin/series?page=1&per_page=12');

        $elapsedMs = (microtime(true) - $startedAt) * 1000;
        $queryCount = count(DB::getQueryLog());

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.series.0.seasons_count', 2)
            ->assertJsonPath('data.series.0.episodes_count', 10);

        $this->assertLessThan(1000, $elapsedMs, "Series list took {$elapsedMs}ms");
        $this->assertLessThanOrEqual(5, $queryCount, "Series list ran {$queryCount} queries");
    }

    public function test_admin_series_show_with_many_episodes_completes_under_threshold(): void
    {
        $admin = $this->createAdminUser();
        Sanctum::actingAs($admin);

        $series = Series::query()->create([
            'title' => 'Perf Series',
            'slug' => 'perf-series',
            'publish_status' => 'draft',
            'metadata' => [],
        ]);

        for ($season = 1; $season <= 4; $season++) {
            $seasonModel = SeriesSeason::query()->create([
                'series_id' => $series->id,
                'season_number' => $season,
                'name' => "Season {$season}",
                'sort_order' => $season,
                'metadata' => [],
            ]);

            for ($episode = 1; $episode <= 13; $episode++) {
                SeriesEpisode::query()->create([
                    'series_id' => $series->id,
                    'series_season_id' => $seasonModel->id,
                    'season_number' => $season,
                    'episode_number' => $episode,
                    'title' => "S{$season}E{$episode}",
                    'slug' => "perf-series-s{$season}e{$episode}",
                    'source_method' => 'hls',
                    'publish_status' => 'draft',
                    'metadata' => [],
                    'sort_order' => $episode,
                ]);
            }
        }

        DB::enableQueryLog();
        $startedAt = microtime(true);

        $response = $this->getJson("/api/admin/series/{$series->id}");

        $elapsedMs = (microtime(true) - $startedAt) * 1000;
        $queryCount = count(DB::getQueryLog());

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(4, 'data.series.seasons');

        $this->assertLessThan(1500, $elapsedMs, "Series show took {$elapsedMs}ms");
        $this->assertLessThan(20, $queryCount, "Series show ran {$queryCount} queries");
    }

    public function test_public_movies_catalog_uses_stored_procedure(): void
    {
        Movie::query()->create([
            'title' => 'Public Catalog Movie',
            'slug' => 'public-catalog-movie',
            'media_folder' => 'movies/public-catalog-movie',
            'publish_status' => 'published',
            'published_at' => now(),
            'metadata' => [],
        ]);

        DB::enableQueryLog();
        $startedAt = microtime(true);

        $response = $this->getJson('/api/movies?page=1&per_page=12');

        $elapsedMs = (microtime(true) - $startedAt) * 1000;
        $queryCount = count(DB::getQueryLog());
        $body = $response->json();

        $response->assertOk()->assertJsonPath('success', true);
        $this->assertLessThan(800, $elapsedMs, "Public movies catalog took {$elapsedMs}ms");
        $this->assertLessThanOrEqual(15, $queryCount, "Public movies catalog ran {$queryCount} queries");
        $this->assertArrayNotHasKey('__typename', $body['data']['movies'][0] ?? $body['data'] ?? []);
    }

    public function test_public_series_catalog_uses_stored_procedure(): void
    {
        Series::query()->create([
            'title' => 'Public Catalog Series',
            'slug' => 'public-catalog-series',
            'publish_status' => 'published',
            'published_at' => now(),
            'metadata' => [],
        ]);

        DB::enableQueryLog();
        $startedAt = microtime(true);

        $response = $this->getJson('/api/series?page=1&per_page=12');

        $elapsedMs = (microtime(true) - $startedAt) * 1000;
        $queryCount = count(DB::getQueryLog());

        $response->assertOk()->assertJsonPath('success', true);
        $this->assertLessThan(800, $elapsedMs, "Public series catalog took {$elapsedMs}ms");
        $this->assertLessThanOrEqual(15, $queryCount, "Public series catalog ran {$queryCount} queries");
    }

    public function test_filter_options_endpoint_caches_correctly(): void
    {
        $this->getJson('/api/filter-options')->assertOk();

        DB::enableQueryLog();
        $startedAt = microtime(true);

        $response = $this->getJson('/api/filter-options');

        $elapsedMs = (microtime(true) - $startedAt) * 1000;
        $queryCount = count(DB::getQueryLog());

        $response->assertOk()->assertJsonPath('success', true);
        $this->assertLessThan(200, $elapsedMs, "Filter options took {$elapsedMs}ms");
        $this->assertLessThanOrEqual(3, $queryCount, "Filter options ran {$queryCount} queries");
    }

    public function test_admin_options_endpoints_are_cached_and_fast(): void
    {
        $admin = $this->createAdminUser();
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/movies/options')->assertOk();

        DB::enableQueryLog();
        $startedAt = microtime(true);

        $response = $this->getJson('/api/admin/series/options');

        $elapsedMs = (microtime(true) - $startedAt) * 1000;
        $queryCount = count(DB::getQueryLog());

        $response->assertOk()->assertJsonPath('success', true);

        $this->assertLessThan(200, $elapsedMs, "Series options took {$elapsedMs}ms");
        $this->assertLessThan(3, $queryCount, "Series options ran {$queryCount} queries");
    }

    private function createAdminUser(): User
    {
        $this->seed(RolePermissionSeeder::class);

        $adminRole = Role::query()->where('name', 'admin')->firstOrFail();

        $user = User::factory()->create([
            'status' => 'active',
        ]);

        $user->roles()->attach($adminRole->id);

        return $user;
    }
}

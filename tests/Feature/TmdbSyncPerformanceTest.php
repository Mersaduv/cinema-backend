<?php

namespace Tests\Feature;

use App\Models\Genre;
use App\Models\Role;
use App\Models\User;
use App\Services\Cinema\GenreCatalogService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TmdbSyncPerformanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.tmdb.access_token', 'test-token');
        config()->set('services.tmdb.api_key', '');
        config()->set('services.tmdb.base_url', 'https://api.themoviedb.org/3');
        config()->set('services.tmdb.image_base_url', 'https://image.tmdb.org/t/p');
        config()->set('services.tmdb.default_language', 'fa-IR');
        config()->set('services.tmdb.verify_ssl', false);

        Cache::flush();
    }

    public function test_movie_fetch_returns_quickly_on_cached_response(): void
    {
        $admin = $this->createAdminUser();
        Sanctum::actingAs($admin);

        $this->warmMovieTmdbCache(1396);

        $startedAt = microtime(true);

        $response = $this->getJson('/api/admin/tmdb/fetch?tmdb_id=1396&language=fa-IR');

        $elapsedMs = (microtime(true) - $startedAt) * 1000;

        $response->assertOk()->assertJsonPath('success', true);
        $this->assertLessThan(300, $elapsedMs, "Cached movie fetch took {$elapsedMs}ms");
    }

    public function test_tv_fetch_without_seasons_is_fast(): void
    {
        $admin = $this->createAdminUser();
        Sanctum::actingAs($admin);

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/genre/tv/list')) {
                return Http::response(['genres' => [['id' => 18, 'name' => 'درام']]], 200);
            }

            if (str_contains($request->url(), '/tv/1396') && ! str_contains($request->url(), '/season/')) {
                return Http::response($this->tvOverviewPayload(), 200);
            }

            return Http::response([], 404);
        });

        $startedAt = microtime(true);

        $response = $this->getJson('/api/admin/tmdb/tv/fetch?tmdb_id=1396&language=fa-IR&include_seasons=0');

        $elapsedMs = (microtime(true) - $startedAt) * 1000;

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.series.tmdb_id', 1396)
            ->assertJsonMissingPath('data.series.seasons');

        $this->assertLessThan(500, $elapsedMs, "TV overview fetch took {$elapsedMs}ms");
    }

    public function test_tv_fetch_with_five_seasons_runs_in_parallel_not_sequentially(): void
    {
        $admin = $this->createAdminUser();
        Sanctum::actingAs($admin);

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/genre/tv/list')) {
                return Http::response(['genres' => [['id' => 18, 'name' => 'درام']]], 200);
            }

            if ($this->isSeasonDetailRequest($request->url())) {
                $seasonNumber = (int) preg_replace('#.*/season/(\d+).*#', '$1', $request->url());

                return Http::response($this->tvSeasonPayload($seasonNumber), 200);
            }

            if (str_contains($request->url(), '/tv/1396') && ! str_contains($request->url(), '/season/')) {
                return Http::response($this->tvOverviewPayload(), 200);
            }

            return Http::response([], 404);
        });

        $response = $this->getJson('/api/admin/tmdb/tv/fetch?tmdb_id=1396&language=fa-IR&include_seasons=1');

        $response->assertOk()->assertJsonPath('success', true);
        $this->assertCount(5, $response->json('data.series.seasons'));

        $seasonDetailCalls = collect(Http::recorded())
            ->filter(fn (array $pair) => $this->isSeasonDetailRequest($pair[0]->url()))
            ->count();

        $this->assertSame(
            10,
            $seasonDetailCalls,
            'Expected exactly 10 season detail HTTP calls (5 seasons x fa-IR + en-US), not one loop per season',
        );
    }

    public function test_tmdb_cache_is_hit_on_second_identical_request(): void
    {
        $admin = $this->createAdminUser();
        Sanctum::actingAs($admin);

        $httpCalls = 0;

        Http::fake(function (Request $request) use (&$httpCalls) {
            if (str_contains($request->url(), '/genre/movie/list')) {
                return Http::response(['genres' => [['id' => 12, 'name' => 'ماجراجویی']]], 200);
            }

            if (str_contains($request->url(), '/movie/1396')) {
                $httpCalls++;

                return Http::response($this->moviePayload(), 200);
            }

            return Http::response([], 404);
        });

        $this->getJson('/api/admin/tmdb/fetch?tmdb_id=1396&language=fa-IR')->assertOk();
        $this->getJson('/api/admin/tmdb/fetch?tmdb_id=1396&language=fa-IR')->assertOk();

        $this->assertSame(2, $httpCalls, 'Expected one fa-IR and one en-US HTTP call total for two API requests');
    }

    public function test_single_failed_season_does_not_fail_entire_tv_fetch(): void
    {
        $admin = $this->createAdminUser();
        Sanctum::actingAs($admin);

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/genre/tv/list')) {
                return Http::response(['genres' => [['id' => 18, 'name' => 'درام']]], 200);
            }

            if ($this->isSeasonDetailRequest($request->url())) {
                $seasonNumber = (int) preg_replace('#.*/season/(\d+).*#', '$1', $request->url());

                if ($seasonNumber === 3) {
                    return Http::response(['status_code' => 34], 404);
                }

                return Http::response($this->tvSeasonPayload($seasonNumber), 200);
            }

            if (str_contains($request->url(), '/tv/1396') && ! str_contains($request->url(), '/season/')) {
                return Http::response($this->tvOverviewPayload(), 200);
            }

            return Http::response([], 404);
        });

        $response = $this->getJson('/api/admin/tmdb/tv/fetch?tmdb_id=1396&language=fa-IR&include_seasons=1');

        $response->assertOk()->assertJsonPath('success', true);

        $seasonErrors = $response->json('data.series.season_errors');
        $this->assertIsArray($seasonErrors);
        $this->assertTrue(
            isset($seasonErrors[3]) || isset($seasonErrors['3']),
            'Expected season 3 to be reported in season_errors',
        );

        $successfulSeasons = collect($response->json('data.series.seasons'))
            ->pluck('season_number')
            ->all();

        $this->assertContains(1, $successfulSeasons);
        $this->assertContains(2, $successfulSeasons);
        $this->assertContains(4, $successfulSeasons);
        $this->assertContains(5, $successfulSeasons);
    }

    public function test_enrich_genres_for_tmdb_payload_uses_batch_lookup(): void
    {
        Genre::query()->insert([
            [
                'tmdb_id' => 18,
                'name' => 'درام',
                'slug' => 'drama',
                'type' => 'tv',
                'display_order' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'tmdb_id' => 10759,
                'name' => 'اکشن و ماجراجویی',
                'slug' => 'action-adventure',
                'type' => 'tv',
                'display_order' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::enableQueryLog();

        $service = app(GenreCatalogService::class);
        $result = $service->enrichGenresForTmdbPayload([
            ['id' => 18, 'name' => 'Drama'],
            ['id' => 10759, 'name' => 'Action & Adventure'],
        ], 'tv');

        $queryCount = count(DB::getQueryLog());

        $this->assertCount(2, $result);
        $this->assertLessThanOrEqual(2, $queryCount, "Genre enrichment ran {$queryCount} queries; expected batch lookup");
    }

    private function warmMovieTmdbCache(int $tmdbId): void
    {
        Http::fake(function (Request $request) use ($tmdbId) {
            if (str_contains($request->url(), '/genre/movie/list')) {
                return Http::response(['genres' => [['id' => 12, 'name' => 'ماجراجویی']]], 200);
            }

            if (str_contains($request->url(), "/movie/{$tmdbId}")) {
                return Http::response($this->moviePayload(), 200);
            }

            return Http::response([], 404);
        });

        $this->getJson("/api/admin/tmdb/fetch?tmdb_id={$tmdbId}&language=fa-IR")->assertOk();
    }

    /**
     * @return array<string, mixed>
     */
    private function moviePayload(): array
    {
        return [
            'id' => 1396,
            'title' => 'فیلم تست',
            'original_title' => 'Test Movie',
            'overview' => 'خلاصه',
            'poster_path' => '/poster.jpg',
            'backdrop_path' => '/backdrop.jpg',
            'release_date' => '2020-01-01',
            'runtime' => 120,
            'status' => 'Released',
            'vote_average' => 8.1,
            'original_language' => 'en',
            'genres' => [['id' => 12, 'name' => 'Adventure']],
            'spoken_languages' => [['iso_639_1' => 'en', 'name' => 'English', 'english_name' => 'English']],
            'production_countries' => [['iso_3166_1' => 'US', 'name' => 'United States of America']],
            'videos' => ['results' => []],
            'credits' => ['cast' => [], 'crew' => []],
            'external_ids' => ['imdb_id' => 'tt0133093'],
            'keywords' => ['keywords' => []],
            'release_dates' => ['results' => []],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function tvOverviewPayload(): array
    {
        return [
            'id' => 1396,
            'name' => 'سریال تست',
            'original_name' => 'Test Series',
            'overview' => 'خلاصه سریال',
            'poster_path' => '/poster.jpg',
            'backdrop_path' => '/backdrop.jpg',
            'first_air_date' => '2019-01-01',
            'last_air_date' => '2024-01-01',
            'vote_average' => 8.5,
            'original_language' => 'en',
            'number_of_seasons' => 5,
            'number_of_episodes' => 50,
            'status' => 'Returning Series',
            'type' => 'Scripted',
            'genres' => [['id' => 18, 'name' => 'Drama']],
            'spoken_languages' => [['iso_639_1' => 'en', 'name' => 'English', 'english_name' => 'English']],
            'origin_country' => ['US'],
            'networks' => [['name' => 'HBO']],
            'created_by' => [],
            'aggregate_credits' => ['cast' => [], 'crew' => []],
            'videos' => ['results' => []],
            'external_ids' => ['imdb_id' => 'tt0944947', 'tvdb_id' => 121361],
            'keywords' => ['results' => []],
            'content_ratings' => ['results' => [['iso_3166_1' => 'US', 'rating' => 'TV-MA']]],
            'episode_run_time' => [60],
            'seasons' => [
                ['season_number' => 1, 'episode_count' => 10, 'name' => 'Season 1'],
                ['season_number' => 2, 'episode_count' => 10, 'name' => 'Season 2'],
                ['season_number' => 3, 'episode_count' => 10, 'name' => 'Season 3'],
                ['season_number' => 4, 'episode_count' => 10, 'name' => 'Season 4'],
                ['season_number' => 5, 'episode_count' => 10, 'name' => 'Season 5'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function tvSeasonPayload(int $seasonNumber): array
    {
        return [
            'season_number' => $seasonNumber,
            'name' => "Season {$seasonNumber}",
            'overview' => "Season {$seasonNumber} overview",
            'air_date' => '2019-01-01',
            'episode_count' => 2,
            'poster_path' => '/season.jpg',
            'episodes' => [
                [
                    'episode_number' => 1,
                    'name' => 'Episode 1',
                    'overview' => 'Episode one',
                    'air_date' => '2019-01-01',
                    'runtime' => 60,
                    'still_path' => '/still.jpg',
                ],
                [
                    'episode_number' => 2,
                    'name' => 'Episode 2',
                    'overview' => 'Episode two',
                    'air_date' => '2019-01-08',
                    'runtime' => 60,
                    'still_path' => '/still2.jpg',
                ],
            ],
        ];
    }

    private function isSeasonDetailRequest(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '';

        return preg_match('#/tv/\d+/season/\d+$#', $path) === 1;
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

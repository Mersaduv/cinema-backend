<?php

namespace Tests\Feature;

use App\Models\Movie;
use App\Models\ProductionCountry;
use App\Services\Cinema\CatalogFilterQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class CatalogCountryFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_movies_catalog_filters_by_united_states_iso(): void
    {
        ProductionCountry::query()->create([
            'iso_3166_1' => 'US',
            'name' => 'ایالات متحده آمریکا',
            'native_name' => 'ایالات متحده آمریکا',
            'english_name' => 'United States of America',
        ]);

        Movie::query()->create([
            'title' => 'American Movie',
            'slug' => 'american-movie',
            'media_folder' => 'movies/american-movie',
            'publish_status' => 'published',
            'published_at' => now(),
            'origin_country' => 'US',
            'metadata' => [
                'tmdb' => [
                    'countries' => ['آمریکا'],
                    'country_isos' => ['US'],
                ],
            ],
        ]);

        Movie::query()->create([
            'title' => 'Iranian Movie',
            'slug' => 'iranian-movie',
            'media_folder' => 'movies/iranian-movie',
            'publish_status' => 'published',
            'published_at' => now(),
            'origin_country' => 'IR',
            'metadata' => [
                'tmdb' => [
                    'countries' => ['ایران'],
                    'country_isos' => ['IR'],
                ],
            ],
        ]);

        $response = $this->getJson('/api/movies?countries=US');

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data.movies')
            ->assertJsonPath('data.movies.0.slug', 'american-movie');
    }

    public function test_procedure_params_keep_all_selected_countries(): void
    {
        $request = Request::create('/api/movies?countries=US,FR', 'GET');
        $params = app(CatalogFilterQuery::class)->procedureParams($request, 'movies');

        $this->assertSame('US,FR', $params['countries']);
    }

    public function test_public_movies_catalog_filters_by_multiple_countries_with_or_logic(): void
    {
        ProductionCountry::query()->insert([
            [
                'iso_3166_1' => 'US',
                'name' => 'ایالات متحده آمریکا',
                'native_name' => 'ایالات متحده آمریکا',
                'english_name' => 'United States of America',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'iso_3166_1' => 'IR',
                'name' => 'ایران',
                'native_name' => 'ایران',
                'english_name' => 'Iran',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'iso_3166_1' => 'FR',
                'name' => 'فرانسه',
                'native_name' => 'فرانسه',
                'english_name' => 'France',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        Movie::query()->create([
            'title' => 'American Movie',
            'slug' => 'american-movie',
            'media_folder' => 'movies/american-movie',
            'publish_status' => 'published',
            'published_at' => now(),
            'origin_country' => 'US',
            'metadata' => [
                'tmdb' => [
                    'countries' => ['آمریکا'],
                    'country_isos' => ['US'],
                ],
            ],
        ]);

        Movie::query()->create([
            'title' => 'French Movie',
            'slug' => 'french-movie',
            'media_folder' => 'movies/french-movie',
            'publish_status' => 'published',
            'published_at' => now(),
            'origin_country' => 'FR',
            'metadata' => [
                'tmdb' => [
                    'countries' => ['فرانسه'],
                    'country_isos' => ['FR'],
                ],
            ],
        ]);

        Movie::query()->create([
            'title' => 'Japanese Movie',
            'slug' => 'japanese-movie',
            'media_folder' => 'movies/japanese-movie',
            'publish_status' => 'published',
            'published_at' => now(),
            'origin_country' => 'JP',
            'metadata' => [
                'tmdb' => [
                    'countries' => ['ژاپن'],
                    'country_isos' => ['JP'],
                ],
            ],
        ]);

        $this->getJson('/api/movies?countries=US')
            ->assertOk()
            ->assertJsonCount(1, 'data.movies')
            ->assertJsonPath('data.movies.0.slug', 'american-movie');

        $this->getJson('/api/movies?countries=FR')
            ->assertOk()
            ->assertJsonCount(1, 'data.movies')
            ->assertJsonPath('data.movies.0.slug', 'french-movie');

        $this->getJson('/api/movies?countries=US,FR')
            ->assertOk()
            ->assertJsonCount(2, 'data.movies')
            ->assertJsonPath('data.pagination.total', 2);

        $this->getJson('/api/movies?countries=US,IR,FR')
            ->assertOk()
            ->assertJsonCount(2, 'data.movies')
            ->assertJsonPath('data.pagination.total', 2);

        $slugs = collect($this->getJson('/api/movies?countries=US,IR,FR')->json('data.movies'))
            ->pluck('slug')
            ->all();

        $this->assertContains('american-movie', $slugs);
        $this->assertContains('french-movie', $slugs);
        $this->assertNotContains('japanese-movie', $slugs);
    }

    public function test_backfill_sets_origin_country_from_persian_label(): void
    {
        ProductionCountry::query()->create([
            'iso_3166_1' => 'US',
            'name' => 'ایالات متحده آمریکا',
            'native_name' => 'ایالات متحده آمریکا',
            'english_name' => 'United States of America',
        ]);

        $movie = Movie::query()->create([
            'title' => 'Legacy American Movie',
            'slug' => 'legacy-american-movie',
            'media_folder' => 'movies/legacy-american-movie',
            'publish_status' => 'published',
            'published_at' => now(),
            'origin_country' => null,
            'metadata' => [
                'tmdb' => [
                    'countries' => ['آمریکا'],
                ],
            ],
        ]);

        $this->artisan('cinema:backfill-genres')->assertSuccessful();

        $movie->refresh();

        $this->assertSame('US', $movie->origin_country);
        $this->assertSame(['US'], data_get($movie->metadata, 'tmdb.country_isos'));

        $this->getJson('/api/movies?countries=US')
            ->assertOk()
            ->assertJsonCount(1, 'data.movies')
            ->assertJsonPath('data.movies.0.slug', 'legacy-american-movie');
    }
}

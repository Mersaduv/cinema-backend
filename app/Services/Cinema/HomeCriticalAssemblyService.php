<?php

namespace App\Services\Cinema;

use App\Http\Controllers\PublicMovieController;
use App\Http\Controllers\PublicSeriesController;
use Illuminate\Http\Request;

class HomeCriticalAssemblyService
{
    private const FEATURED_POOL = 7;
    private const RAIL_PER_PAGE = 6;
    private const EDITORIAL_MAX = 7;
    /** Slim newest pools merged client-side into the first home rail (cap 20). */
    private const SITE_FRESH_POOL = 8;

    /**
     * Above-the-fold home bootstrap only.
     * filter_options (~28KB) load in deferred genre rails — MovieLand-style split.
     *
     * @return array<string, mixed>
     */
    public function assemble(): array
    {
        return [
            'latest_movies' => $this->movies(['sort' => 'release_year_desc', 'page' => 1, 'per_page' => self::FEATURED_POOL]),
            'latest_series' => $this->series(['sort' => 'release_year_desc', 'page' => 1, 'per_page' => self::FEATURED_POOL]),
            'editorial_featured_movies' => $this->movies(['sort' => 'featured', 'page' => 1, 'per_page' => self::EDITORIAL_MAX]),
            'editorial_featured_series' => $this->series(['sort' => 'featured', 'page' => 1, 'per_page' => self::EDITORIAL_MAX]),
            'hero_hybrid_movies' => $this->movies(['sort' => 'popular_hybrid', 'page' => 1, 'per_page' => self::FEATURED_POOL]),
            'hero_hybrid_series' => $this->series(['sort' => 'popular_hybrid', 'page' => 1, 'per_page' => self::FEATURED_POOL]),
            'trending_movies' => $this->movies(['sort' => 'trending', 'carousel' => '1', 'page' => 1, 'per_page' => self::RAIL_PER_PAGE]),
            'trending_series' => $this->series(['sort' => 'trending', 'carousel' => '1', 'page' => 1, 'per_page' => self::RAIL_PER_PAGE]),
            'trending_fallback_movies' => $this->movies(['sort' => 'popular', 'carousel' => '1', 'page' => 1, 'per_page' => self::RAIL_PER_PAGE]),
            'trending_fallback_series' => $this->series(['sort' => 'popular', 'carousel' => '1', 'page' => 1, 'per_page' => self::RAIL_PER_PAGE]),
            'popular_movies' => $this->movies(['sort' => 'popular', 'carousel' => '1', 'page' => 1, 'per_page' => self::RAIL_PER_PAGE]),
            'site_fresh_movies' => $this->movies(['sort' => 'newest', 'carousel' => '1', 'page' => 1, 'per_page' => self::SITE_FRESH_POOL]),
            'site_fresh_series' => $this->series(['sort' => 'episode_arrived', 'carousel' => '1', 'page' => 1, 'per_page' => self::SITE_FRESH_POOL]),
        ];
    }

    /**
     * @param  array<string, scalar|null>  $query
     * @return array<string, mixed>
     */
    private function movies(array $query): array
    {
        $request = Request::create('/api/movies', 'GET', $query);
        $response = app(PublicMovieController::class)->index($request);
        $payload = (array) $response->getData(true);

        if (isset($payload['meta'])) {
            return [
                'items' => $payload['data'] ?? [],
                'meta' => $payload['meta'] ?? [],
                'error' => '',
            ];
        }

        $data = (array) ($payload['data'] ?? []);

        return [
            'items' => $data['movies'] ?? [],
            'meta' => $data['pagination'] ?? [],
            'error' => '',
        ];
    }

    /**
     * @param  array<string, scalar|null>  $query
     * @return array<string, mixed>
     */
    private function series(array $query): array
    {
        $request = Request::create('/api/series', 'GET', $query);
        $response = app(PublicSeriesController::class)->index($request);
        $payload = (array) $response->getData(true);

        if (isset($payload['meta'])) {
            return [
                'items' => $payload['data'] ?? [],
                'meta' => $payload['meta'] ?? [],
                'error' => '',
            ];
        }

        $data = (array) ($payload['data'] ?? []);

        return [
            'items' => $data['series'] ?? [],
            'meta' => $data['pagination'] ?? [],
            'error' => '',
        ];
    }
}

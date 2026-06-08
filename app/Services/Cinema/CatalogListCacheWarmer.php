<?php

namespace App\Services\Cinema;

use App\Support\CatalogListResponseMapper;
use Illuminate\Support\Facades\Cache;

class CatalogListCacheWarmer
{
    public function __construct(
        private readonly CatalogListProcedureService $catalogListProcedureService,
        private readonly CatalogListResponseMapper $catalogListResponseMapper,
        private readonly CatalogCacheService $catalogCacheService,
    ) {
    }

    public function warmDefaultLists(): void
    {
        $this->warmPublicMoviesCatalog();
        $this->warmPublicSeriesCatalog();
        $this->warmAdminMoviesList();
        $this->warmAdminSeriesList();
    }

    private function warmPublicMoviesCatalog(): void
    {
        $page = 1;
        $perPage = 24;
        $cacheKey = $this->catalogCacheService->listCacheKey(
            'movies_list',
            md5('page=1&per_page=24'),
        );

        Cache::put($cacheKey, $this->buildPublicMoviesPayload($page, $perPage), 300);
    }

    private function warmPublicSeriesCatalog(): void
    {
        $page = 1;
        $perPage = 24;
        $cacheKey = $this->catalogCacheService->listCacheKey(
            'series_list',
            md5('page=1&per_page=24'),
        );

        Cache::put($cacheKey, $this->buildPublicSeriesPayload($page, $perPage), 300);
    }

    private function warmAdminMoviesList(): void
    {
        $page = 1;
        $perPage = 12;
        $cacheKey = $this->catalogCacheService->listCacheKey(
            'admin_movies_list',
            md5(serialize([
                $page,
                $perPage,
                null,
                null,
                null,
            ])),
        );

        Cache::put($cacheKey, $this->buildAdminMoviesPayload($page, $perPage), 60);
    }

    private function warmAdminSeriesList(): void
    {
        $page = 1;
        $perPage = 12;
        $cacheKey = $this->catalogCacheService->listCacheKey(
            'admin_series_list',
            md5(serialize([
                $page,
                $perPage,
                null,
                null,
                null,
            ])),
        );

        Cache::put($cacheKey, $this->buildAdminSeriesPayload($page, $perPage), 60);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPublicMoviesPayload(int $page, int $perPage): array
    {
        $result = $this->catalogListProcedureService->publicMoviesCatalog(
            null,
            '',
            '',
            '',
            null,
            null,
            null,
            'newest',
            $page,
            $perPage,
        );

        $movieIds = array_map(fn (array $row) => (int) $row['id'], $result['rows']);
        $genreMap = $this->catalogListResponseMapper->movieGenreMap($movieIds);

        $movies = array_map(
            fn (array $row) => $this->catalogListResponseMapper->publicMovieListItem($row, $genreMap),
            $result['rows'],
        );

        $lastPage = max(1, (int) ceil($result['total'] / $perPage));

        return [
            'movies' => $movies,
            'pagination' => [
                'total' => $result['total'],
                'per_page' => $perPage,
                'current_page' => $page,
                'last_page' => $lastPage,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPublicSeriesPayload(int $page, int $perPage): array
    {
        $result = $this->catalogListProcedureService->publicSeriesCatalog(
            null,
            '',
            '',
            '',
            null,
            null,
            null,
            'newest',
            $page,
            $perPage,
        );

        $seriesIds = array_map(fn (array $row) => (int) $row['id'], $result['rows']);
        $genreMap = $this->catalogListResponseMapper->seriesGenreMap($seriesIds);

        $series = array_map(
            fn (array $row) => $this->catalogListResponseMapper->publicSeriesListItem($row, $genreMap),
            $result['rows'],
        );

        $lastPage = max(1, (int) ceil($result['total'] / $perPage));

        return [
            'series' => $series,
            'pagination' => [
                'total' => $result['total'],
                'per_page' => $perPage,
                'current_page' => $page,
                'last_page' => $lastPage,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildAdminMoviesPayload(int $page, int $perPage): array
    {
        $result = $this->catalogListProcedureService->adminMoviesList(
            null,
            null,
            null,
            $page,
            $perPage,
        );

        $movies = array_map(
            fn (array $row) => $this->catalogListResponseMapper->adminMovieListItem($row),
            $result['rows'],
        );

        $lastPage = max(1, (int) ceil($result['total'] / $perPage));

        return [
            'movies' => $movies,
            'summary' => $result['summary'],
            'pagination' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $result['total'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildAdminSeriesPayload(int $page, int $perPage): array
    {
        $result = $this->catalogListProcedureService->adminSeriesList(
            null,
            null,
            null,
            $page,
            $perPage,
        );

        $series = array_map(
            fn (array $row) => $this->catalogListResponseMapper->adminSeriesListItem($row),
            $result['rows'],
        );

        $lastPage = max(1, (int) ceil($result['total'] / $perPage));

        return [
            'series' => $series,
            'summary' => $result['summary'],
            'pagination' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $result['total'],
            ],
        ];
    }
}

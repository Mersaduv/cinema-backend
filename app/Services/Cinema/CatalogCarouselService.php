<?php

namespace App\Services\Cinema;

use App\Models\Movie;
use App\Models\Series;
use Illuminate\Http\Request;

class CatalogCarouselService
{
    public function __construct(
        private readonly CatalogFilterQuery $catalogFilterQuery,
        private readonly CatalogListProcedureService $catalogListProcedureService,
    ) {}

    /**
     * @return array{rows: array<int, array<string, mixed>>, total: int, current_page: int}
     */
    public function fetchMovies(Request $request, int $perPage): array
    {
        $cursor = CatalogCarouselCursor::decode($request->string('cursor')->toString() ?: null);
        $params = $this->catalogFilterQuery->procedureParams($request, 'movies');

        if ($cursor === null) {
            $page = max(1, (int) $request->integer('page', 1));
            $result = $this->catalogListProcedureService->publicMoviesCatalog(
                $params['search'],
                $params['genre_ids'],
                $params['countries'],
                $params['languages'],
                $params['year_from'],
                $params['year_to'],
                $params['min_rating'],
                $params['sort'],
                $page,
                $perPage + 1,
            );

            return $this->finalizeResult($result['rows'], $result['total'], $page, $perPage);
        }

        $query = Movie::query()->where('publish_status', 'published');
        $this->catalogFilterQuery->apply($query, $request, 'movies');
        $this->catalogFilterQuery->applyCursor($query, $cursor, $params['sort'], 'movies');

        $models = $query
            ->limit($perPage + 1)
            ->get($this->movieSelectColumns());

        $rows = $models->map(fn (Movie $movie) => $this->movieToRow($movie))->all();
        $total = $this->countMovies($request);

        return $this->finalizeResult($rows, $total, 0, $perPage);
    }

    /**
     * @return array{rows: array<int, array<string, mixed>>, total: int, current_page: int}
     */
    public function fetchSeries(Request $request, int $perPage): array
    {
        $cursor = CatalogCarouselCursor::decode($request->string('cursor')->toString() ?: null);
        $params = $this->catalogFilterQuery->procedureParams($request, 'series');

        if (strtolower(trim((string) ($params['sort'] ?? ''))) === 'episode_arrived') {
            return $this->fetchSeriesByEpisodeArrival($request, $perPage, $cursor);
        }

        if ($cursor === null) {
            $page = max(1, (int) $request->integer('page', 1));
            $result = $this->catalogListProcedureService->publicSeriesCatalog(
                $params['search'],
                $params['genre_ids'],
                $params['countries'],
                $params['languages'],
                $params['year_from'],
                $params['year_to'],
                $params['min_rating'],
                $params['sort'],
                $page,
                $perPage + 1,
            );

            return $this->finalizeResult($result['rows'], $result['total'], $page, $perPage);
        }

        $query = Series::query()->where('publish_status', 'published');
        $this->catalogFilterQuery->apply($query, $request, 'series');
        $this->catalogFilterQuery->applyCursor($query, $cursor, $params['sort'], 'series');

        $models = $query
            ->limit($perPage + 1)
            ->get($this->seriesSelectColumns());

        $rows = $models->map(fn (Series $series) => $this->seriesToRow($series))->all();
        $total = $this->countSeries($request);

        return $this->finalizeResult($rows, $total, 0, $perPage);
    }

    /**
     * @param  array{id: int, sort: mixed}|null  $cursor
     * @return array{rows: array<int, array<string, mixed>>, total: int, current_page: int}
     */
    private function fetchSeriesByEpisodeArrival(Request $request, int $perPage, ?array $cursor): array
    {
        $query = Series::query()->where('publish_status', 'published');
        $this->catalogFilterQuery->apply($query, $request, 'series');
        $this->catalogFilterQuery->applyCursor($query, $cursor, 'episode_arrived', 'series');

        $columns = array_map(
            fn (string $column) => 'series.'.$column,
            $this->seriesSelectColumns(),
        );
        $query->select($columns);
        $query->addSelect([
            'episode_arrived_at' => (new EpisodeArrival())->latestArrivedAtQuery(),
        ]);

        $models = $query->limit($perPage + 1)->get();
        $rows = $models->map(fn (Series $series) => $this->seriesToRow($series))->all();
        $total = $this->countSeries($request);

        return $this->finalizeResult($rows, $total, max(1, (int) $request->integer('page', 1)), $perPage);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{rows: array<int, array<string, mixed>>, total: int, current_page: int}
     */
    private function finalizeResult(array $rows, int $total, int $currentPage, int $perPage): array
    {
        $hasExtra = count($rows) > $perPage;

        if ($hasExtra) {
            $rows = array_slice($rows, 0, $perPage);
        }

        return [
            'rows' => $rows,
            'total' => $total,
            'current_page' => $currentPage,
            'has_extra' => $hasExtra,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function movieToRow(Movie $movie): array
    {
        return [
            'id' => $movie->id,
            'title' => $movie->title,
            'slug' => $movie->slug,
            'synopsis' => $movie->synopsis,
            'release_year' => $movie->release_year,
            'poster_path' => $movie->poster_path,
            'has_subtitle' => $movie->has_subtitle,
            'has_dubbed_audio' => $movie->has_dubbed_audio,
            'filter_rating' => $movie->filter_rating,
            'filter_popularity' => $movie->filter_popularity ?? null,
            'popular_hybrid_score' => $movie->popular_hybrid_score ?? null,
            'published_at' => $movie->published_at,
            'updated_at' => $movie->updated_at,
            'metadata' => $movie->metadata,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function seriesToRow(Series $series): array
    {
        return [
            'id' => $series->id,
            'title' => $series->title,
            'slug' => $series->slug,
            'original_title' => $series->original_title,
            'synopsis' => $series->synopsis,
            'release_year' => $series->release_year,
            'status' => $series->status,
            'network' => $series->network,
            'poster_url' => $series->poster_url,
            'backdrop_url' => $series->backdrop_url,
            'has_subtitle' => $series->has_subtitle,
            'has_dubbed' => $series->has_dubbed,
            'number_of_seasons' => $series->number_of_seasons,
            'number_of_episodes' => $series->number_of_episodes,
            'filter_rating' => $series->filter_rating,
            'filter_popularity' => $series->filter_popularity ?? null,
            'popular_hybrid_score' => $series->popular_hybrid_score ?? null,
            'published_at' => $series->published_at,
            'updated_at' => $series->updated_at,
            'episode_arrived_at' => $series->getAttribute('episode_arrived_at'),
            'metadata' => $series->metadata,
        ];
    }

    /**
     * @return list<string>
     */
    private function movieSelectColumns(): array
    {
        return [
            'id',
            'title',
            'slug',
            'synopsis',
            'release_year',
            'poster_path',
            'has_subtitle',
            'has_dubbed_audio',
            'filter_rating',
            'filter_popularity',
            'popular_hybrid_score',
            'published_at',
            'updated_at',
            'metadata',
        ];
    }

    /**
     * @return list<string>
     */
    private function seriesSelectColumns(): array
    {
        return [
            'id',
            'title',
            'slug',
            'original_title',
            'synopsis',
            'release_year',
            'status',
            'network',
            'poster_url',
            'backdrop_url',
            'has_subtitle',
            'has_dubbed',
            'number_of_seasons',
            'number_of_episodes',
            'filter_rating',
            'filter_popularity',
            'popular_hybrid_score',
            'published_at',
            'updated_at',
            'metadata',
        ];
    }

    private function countMovies(Request $request): int
    {
        $query = Movie::query()->where('publish_status', 'published');
        $this->catalogFilterQuery->apply($query, $request, 'movies');

        return (int) $query->count();
    }

    private function countSeries(Request $request): int
    {
        $query = Series::query()->where('publish_status', 'published');
        $this->catalogFilterQuery->apply($query, $request, 'series');

        return (int) $query->count();
    }

    public function sortValueFromMovieRow(array $row, string $sort): mixed
    {
        $value = match (strtolower(trim($sort))) {
            'popular' => $row['filter_popularity'] ?? null,
            'popular_hybrid' => $row['popular_hybrid_score'] ?? null,
            'top_rated', 'imdb_desc' => $row['filter_rating'] ?? null,
            'release_year_desc', 'year_desc', 'release_year_asc', 'year_asc' => $row['release_year'] ?? null,
            'title_asc', 'title_desc' => $row['title'] ?? null,
            'oldest', 'newest', 'featured' => $row['published_at'] ?? $row['id'],
            'updated', 'updated_desc' => $row['updated_at'] ?? $row['published_at'] ?? $row['id'],
            'episode_arrived' => $row['episode_arrived_at'] ?? $row['id'],
            default => $row['published_at'] ?? $row['id'],
        };

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return $value;
    }

    public function sortValueFromSeriesRow(array $row, string $sort): mixed
    {
        return $this->sortValueFromMovieRow($row, $sort);
    }
}

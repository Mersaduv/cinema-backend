<?php

namespace App\Services\Cinema;

use App\Models\Genre;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class CatalogFilterQuery
{
    public function __construct(
        private readonly CountryCatalogService $countryCatalogService,
        private readonly GenreCatalogService $genreCatalogService,
    ) {}

    /**
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     */
    public function apply(Builder $query, Request $request, string $table = 'movies'): Builder
    {
        $search = trim((string) $request->string('search'));
        if ($search !== '') {
            $term = '%'.$search.'%';
            $query->where(function (Builder $builder) use ($term): void {
                $builder
                    ->where('title', 'like', $term)
                    ->orWhere('original_title', 'like', $term)
                    ->orWhere('synopsis', 'like', $term);
            });
        }

        $genreFilters = array_values(array_unique(array_merge(
            $this->parseArrayParam($request, 'genre_ids'),
            $this->parseArrayParam($request, 'genres'),
        )));
        if ($genreFilters !== []) {
            $this->applyGenreFilter($query, $genreFilters);
        }

        $country = strtoupper(trim((string) $request->string('country')));
        $countries = array_merge(
            $this->parseArrayParam($request, 'countries'),
            $this->parseArrayParam($request, 'country_ids'),
        );
        if ($country !== '') {
            $countries[] = $country;
        }
        $countries = array_values(array_unique(array_map(
            fn (string $iso) => strtoupper($iso),
            array_filter($countries),
        )));

        if ($countries !== []) {
            $this->applyCountryFilter($query, $countries, $table);
        }

        $language = strtolower(trim((string) $request->string('language')));
        if ($language !== '' && $this->hasColumn($table, 'original_language')) {
            $query->where('original_language', $language);
        }

        $languages = array_merge(
            $this->parseArrayParam($request, 'languages'),
            $this->parseArrayParam($request, 'language_ids'),
        );
        if ($languages !== [] && $this->hasColumn($table, 'original_language')) {
            $query->whereIn('original_language', array_map('strtolower', $languages));
        }

        $year = $request->integer('year');
        if ($year > 0) {
            $query->where('release_year', $year);
        }

        $yearFrom = $request->integer('year_from');
        if ($yearFrom > 0) {
            $query->where('release_year', '>=', $yearFrom);
        }

        $yearTo = $request->integer('year_to');
        if ($yearTo > 0) {
            $query->where('release_year', '<=', $yearTo);
        }

        $ageRating = trim((string) $request->string('age_rating'));
        if ($ageRating !== '') {
            if ($this->hasColumn($table, 'content_rating')) {
                $query->where('content_rating', $ageRating);
            } else {
                $query->where('age_rating', $ageRating);
            }
        }

        $minRating = $request->input('min_rating');
        if ($minRating !== null && $minRating !== '') {
            $this->applyMinRatingFilter($query, (float) $minRating, $table);
        }

        return $this->applySort($query, (string) $request->string('sort'), $table);
    }

    /**
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  array<int, string>  $slugs
     */
    /**
     * @param  array<int, string>  $filters  Genre database ids and/or slugs
     */
    private function applyGenreFilter(Builder $query, array $filters): void
    {
        $numericIds = collect($filters)
            ->filter(fn (string $value) => ctype_digit($value))
            ->map(fn (string $value) => (int) $value)
            ->values()
            ->all();

        $slugs = collect($filters)
            ->reject(fn (string $value) => ctype_digit($value))
            ->values()
            ->all();

        $matched = Genre::query()
            ->where(function (Builder $builder) use ($numericIds, $slugs): void {
                if ($numericIds !== []) {
                    $builder->whereIn('id', $numericIds);
                }
                if ($slugs !== []) {
                    $numericIds === []
                        ? $builder->whereIn('slug', $slugs)
                        : $builder->orWhereIn('slug', $slugs);
                }
            })
            ->get(['id', 'tmdb_id']);

        $genreIds = $matched->pluck('id')->unique()->values()->all();
        $tmdbIds = $matched->pluck('tmdb_id')->filter()->unique()->values()->all();

        if ($tmdbIds !== []) {
            $genreIds = array_values(array_unique(array_merge(
                $genreIds,
                Genre::query()->whereIn('tmdb_id', $tmdbIds)->pluck('id')->all(),
            )));
        }

        $genreIds = $this->genreCatalogService->expandGenreIdsForFilter($genreIds);

        if ($genreIds === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereHas('genres', function (Builder $builder) use ($genreIds): void {
            $builder->whereIn('genres.id', $genreIds);
        });
    }

    /**
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  array<int, string>  $isos
     */
    private function applyCountryFilter(Builder $query, array $isos, string $table): void
    {
        $labels = $this->countryCatalogService->labelsForIsos($isos);

        $query->where(function (Builder $outer) use ($isos, $labels, $table): void {
            if ($this->hasColumn($table, 'origin_country')) {
                $outer->whereIn('origin_country', $isos);
            }

            foreach ($labels as $label) {
                $outer->orWhereJsonContains('metadata->tmdb->countries', $label);
            }

            foreach ($isos as $iso) {
                $outer->orWhereJsonContains('metadata->tmdb->country_isos', $iso);
                $outer->orWhere('metadata->tmdb->countries', 'like', '%"'.$iso.'"%');
            }
        });
    }

    /**
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     */
    private function applyMinRatingFilter(Builder $query, float $minRating, string $table): void
    {
        $query->where(function (Builder $outer) use ($minRating, $table): void {
            if ($this->hasColumn($table, 'filter_rating')) {
                $outer->where('filter_rating', '>=', $minRating);
            }

            $outer->orWhere('metadata->tmdb->rating', '>=', $minRating);
        });
    }

    /**
     * @return array{
     *     search: ?string,
     *     genre_ids: string,
     *     countries: string,
     *     languages: string,
     *     year_from: ?int,
     *     year_to: ?int,
     *     min_rating: ?float,
     *     sort: string
     * }
     */
    public function procedureParams(Request $request, string $table = 'movies'): array
    {
        $search = trim((string) $request->string('search'));

        $genreFilters = array_values(array_unique(array_merge(
            $this->parseArrayParam($request, 'genre_ids'),
            $this->parseArrayParam($request, 'genres'),
        )));

        $country = strtoupper(trim((string) $request->string('country')));
        $countries = array_merge(
            $this->parseArrayParam($request, 'countries'),
            $this->parseArrayParam($request, 'country_ids'),
        );
        if ($country !== '') {
            $countries[] = $country;
        }
        $countries = array_values(array_unique(array_map(
            fn (string $iso) => strtoupper($iso),
            array_filter($countries),
        )));

        $language = strtolower(trim((string) $request->string('language')));
        $languages = array_merge(
            $this->parseArrayParam($request, 'languages'),
            $this->parseArrayParam($request, 'language_ids'),
        );
        if ($language !== '') {
            $languages[] = $language;
        }
        $languages = array_values(array_unique(array_map(
            'strtolower',
            array_filter($languages),
        )));

        $year = $request->integer('year');
        $yearFrom = $request->integer('year_from');
        $yearTo = $request->integer('year_to');
        if ($year > 0) {
            $yearFrom = $year;
            $yearTo = $year;
        }

        $minRating = $request->input('min_rating');
        $minRatingValue = ($minRating !== null && $minRating !== '') ? (float) $minRating : null;

        $sort = strtolower(trim((string) $request->string('sort')));

        return [
            'search' => $search !== '' ? $search : null,
            'genre_ids' => $this->resolveGenreIdsForProcedure($genreFilters),
            'countries' => $countries !== [] ? implode(',', $countries) : '',
            'languages' => $languages !== [] ? implode(',', $languages) : '',
            'year_from' => $yearFrom > 0 ? $yearFrom : null,
            'year_to' => $yearTo > 0 ? $yearTo : null,
            'min_rating' => $minRatingValue,
            'sort' => $sort !== '' ? $sort : 'newest',
        ];
    }

    /**
     * @param  array<int, string>  $filters
     */
    private function resolveGenreIdsForProcedure(array $filters): string
    {
        if ($filters === []) {
            return '';
        }

        $numericIds = collect($filters)
            ->filter(fn (string $value) => ctype_digit($value))
            ->map(fn (string $value) => (int) $value)
            ->values()
            ->all();

        $slugs = collect($filters)
            ->reject(fn (string $value) => ctype_digit($value))
            ->values()
            ->all();

        $matched = Genre::query()
            ->where(function (Builder $builder) use ($numericIds, $slugs): void {
                if ($numericIds !== []) {
                    $builder->whereIn('id', $numericIds);
                }
                if ($slugs !== []) {
                    $numericIds === []
                        ? $builder->whereIn('slug', $slugs)
                        : $builder->orWhereIn('slug', $slugs);
                }
            })
            ->get(['id', 'tmdb_id']);

        $genreIds = $matched->pluck('id')->unique()->values()->all();
        $tmdbIds = $matched->pluck('tmdb_id')->filter()->unique()->values()->all();

        if ($tmdbIds !== []) {
            $genreIds = array_values(array_unique(array_merge(
                $genreIds,
                Genre::query()->whereIn('tmdb_id', $tmdbIds)->pluck('id')->all(),
            )));
        }

        $genreIds = $this->genreCatalogService->expandGenreIdsForFilter($genreIds);

        return $genreIds !== [] ? implode(',', $genreIds) : '0';
    }

    /**
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     */
    public function applySort(Builder $query, string $sort, string $table): Builder
    {
        $sort = strtolower(trim($sort));

        if ($sort === '') {
            return $query;
        }

        $query->reorder();

        return match ($sort) {
            'oldest' => $query->orderBy('published_at')->orderBy('id'),
            'title_asc' => $query->orderBy('title')->orderBy('id'),
            'title_desc' => $query->orderByDesc('title')->orderBy('id'),
            'release_year_asc', 'year_asc' => $query->orderBy('release_year')->orderBy('id'),
            'release_year_desc', 'year_desc' => $query->orderByDesc('release_year')->orderByDesc('id'),
            'top_rated', 'imdb_desc' => $this->hasColumn($table, 'filter_rating')
                ? $query->orderByDesc('filter_rating')->orderByDesc('id')
                : $query->orderByDesc('published_at'),
            'popular', 'newest' => $query->orderByDesc('published_at')->orderByDesc('id'),
            default => $query->orderByDesc('published_at')->orderByDesc('id'),
        };
    }

    /**
     * @return array<int, string>
     */
    private function parseArrayParam(Request $request, string $key): array
    {
        $values = $request->input($key);

        if ($values === null) {
            return [];
        }

        if (is_string($values)) {
            $values = explode(',', $values);
        }

        if (! is_array($values)) {
            return [];
        }

        return collect($values)
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function hasColumn(string $table, string $column): bool
    {
        return Schema::hasColumn($table, $column);
    }
}

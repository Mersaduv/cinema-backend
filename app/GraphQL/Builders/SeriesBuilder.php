<?php

namespace App\GraphQL\Builders;

use App\Models\Series;
use App\Services\Cinema\CatalogFilterQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

class SeriesBuilder
{
    public function __invoke(mixed $root, array $args): Builder
    {
        $query = Series::query()->published();

        if (! empty($args['search'])) {
            $term = '%'.$args['search'].'%';
            $query->where(function (Builder $builder) use ($term): void {
                $builder
                    ->where('title', 'like', $term)
                    ->orWhere('original_title', 'like', $term);
            });
        }

        if (! empty($args['year'])) {
            $query->where('release_year', (int) $args['year']);
        }
        if (! empty($args['year_from'])) {
            $query->where('release_year', '>=', (int) $args['year_from']);
        }
        if (! empty($args['year_to'])) {
            $query->where('release_year', '<=', (int) $args['year_to']);
        }

        if (Schema::hasColumn('series', 'filter_rating') && isset($args['min_rating']) && $args['min_rating'] !== null) {
            $query->where('filter_rating', '>=', (float) $args['min_rating']);
        }

        if (Schema::hasColumn('series', 'filter_rating') && isset($args['max_rating']) && $args['max_rating'] !== null) {
            $query->where('filter_rating', '<=', (float) $args['max_rating']);
        }

        if (Schema::hasColumn('series', 'filter_primary_genre') && ! empty($args['genre_id'])) {
            $query->where('filter_primary_genre', (string) $args['genre_id']);
        }
        $genreSlugs = $this->normalizeSlugList($args['genres'] ?? $args['genre_ids'] ?? []);
        if ($genreSlugs !== []) {
            $query->whereHas('genres', function (Builder $builder) use ($genreSlugs): void {
                $builder->whereIn('slug', $genreSlugs);
            });
        } elseif (Schema::hasColumn('series', 'filter_primary_genre')) {
            $this->applyWhereInFilter($query, 'filter_primary_genre', $args['genre_ids'] ?? []);
        }

        if (Schema::hasColumn('series', 'filter_primary_country') && ! empty($args['country_id'])) {
            $query->where('filter_primary_country', (string) $args['country_id']);
        }
        if (Schema::hasColumn('series', 'filter_primary_country')) {
            $this->applyWhereInFilter($query, 'filter_primary_country', $args['country_ids'] ?? []);
        }

        if (Schema::hasColumn('series', 'filter_primary_network') && ! empty($args['network_id'])) {
            $query->where('filter_primary_network', (string) $args['network_id']);
        }
        if (Schema::hasColumn('series', 'filter_primary_network')) {
            $this->applyWhereInFilter($query, 'filter_primary_network', $args['network_ids'] ?? []);
        }

        if (Schema::hasColumn('series', 'origin_country') && ! empty($args['country'])) {
            $query->where('origin_country', strtoupper((string) $args['country']));
        }
        if (Schema::hasColumn('series', 'original_language') && ! empty($args['language'])) {
            $query->where('original_language', strtolower((string) $args['language']));
        }

        $this->applySort($query, (string) ($args['sort_by'] ?? $args['sort'] ?? ''));

        return $query;
    }

    private function applySort(Builder $query, string $sortBy): void
    {
        $normalized = strtolower(trim($sortBy));
        if ($normalized === '') {
            return;
        }

        app(CatalogFilterQuery::class)->applySort($query, $normalized, 'series');
    }

    /**
     * @param  array<int, string>|mixed  $values
     */
    private function applyWhereInFilter(Builder $query, string $column, mixed $values): void
    {
        if (! is_array($values)) {
            return;
        }

        $normalized = collect($values)
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($normalized === []) {
            return;
        }

        $query->whereIn($column, $normalized);
    }

    /**
     * @param  array<int, string>|mixed  $values
     * @return array<int, string>
     */
    private function normalizeSlugList(mixed $values): array
    {
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
}

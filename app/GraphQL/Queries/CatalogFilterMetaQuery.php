<?php

namespace App\GraphQL\Queries;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class CatalogFilterMetaQuery
{
    /**
     * @return array{
     *   genres: array<int, string>,
     *   countries: array<int, string>,
     *   networks: array<int, string>,
     *   years: array<int, int>,
     *   show_types: array<int, string>,
     *   min_rating: float|null,
     *   max_rating: float|null
     * }
     */
    public function __invoke(mixed $root, array $args): array
    {
        $kind = strtolower(trim((string) ($args['kind'] ?? '')));
        if (! in_array($kind, ['movie', 'series'], true)) {
            throw new InvalidArgumentException('kind must be movie or series.');
        }

        $table = $kind === 'movie' ? 'movies' : 'series';

        $baseQuery = DB::table($table)->where('publish_status', 'published');

        $genres = [];
        if (Schema::hasColumn($table, 'filter_primary_genre')) {
            $genres = (clone $baseQuery)
                ->whereNotNull('filter_primary_genre')
                ->where('filter_primary_genre', '!=', '')
                ->distinct()
                ->orderBy('filter_primary_genre')
                ->pluck('filter_primary_genre')
                ->map(fn ($value) => (string) $value)
                ->values()
                ->all();
        }

        $countries = [];
        if (Schema::hasColumn($table, 'filter_primary_country')) {
            $countries = (clone $baseQuery)
                ->whereNotNull('filter_primary_country')
                ->where('filter_primary_country', '!=', '')
                ->distinct()
                ->orderBy('filter_primary_country')
                ->pluck('filter_primary_country')
                ->map(fn ($value) => (string) $value)
                ->values()
                ->all();
        }

        $networks = [];
        if (Schema::hasColumn($table, 'filter_primary_network')) {
            $networks = (clone $baseQuery)
                ->whereNotNull('filter_primary_network')
                ->where('filter_primary_network', '!=', '')
                ->distinct()
                ->orderBy('filter_primary_network')
                ->pluck('filter_primary_network')
                ->map(fn ($value) => (string) $value)
                ->values()
                ->all();
        }

        $years = (clone $baseQuery)
            ->whereNotNull('release_year')
            ->distinct()
            ->orderByDesc('release_year')
            ->pluck('release_year')
            ->map(fn ($value) => (int) $value)
            ->values()
            ->all();

        $ratingRange = null;
        if (Schema::hasColumn($table, 'filter_rating')) {
            $ratingRange = (clone $baseQuery)
                ->selectRaw('MIN(filter_rating) as min_rating, MAX(filter_rating) as max_rating')
                ->first();
        }

        $showTypes = [];
        if ($kind === 'series') {
            $showTypes = (clone $baseQuery)
                ->whereNotNull('show_type')
                ->where('show_type', '!=', '')
                ->distinct()
                ->orderBy('show_type')
                ->pluck('show_type')
                ->map(fn ($value) => (string) $value)
                ->values()
                ->all();
        }

        return [
            'genres' => $genres,
            'countries' => $countries,
            'networks' => $networks,
            'years' => $years,
            'show_types' => $showTypes,
            'min_rating' => isset($ratingRange?->min_rating) ? (float) $ratingRange->min_rating : null,
            'max_rating' => isset($ratingRange?->max_rating) ? (float) $ratingRange->max_rating : null,
        ];
    }
}

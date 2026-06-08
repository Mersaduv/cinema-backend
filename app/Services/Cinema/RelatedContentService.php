<?php

namespace App\Services\Cinema;

use App\Models\Movie;
use App\Models\Series;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class RelatedContentService
{
    /**
     * @return Collection<int, Movie>
     */
    public function relatedMovies(Movie $movie, int $limit = 12): Collection
    {
        $genreIds = $movie->genres()->pluck('genres.id');
        $rating = $movie->filter_rating !== null ? (float) $movie->filter_rating : null;
        $primaryGenre = $movie->filter_primary_genre;
        $country = $movie->origin_country;

        $base = Movie::query()
            ->published()
            ->with(['genres'])
            ->whereKeyNot($movie->id);

        $candidates = $this->rankRelated(
            $base,
            $genreIds,
            $primaryGenre,
            $rating,
            $country,
            null,
            $limit,
        );

        if ($candidates->count() >= $limit) {
            return $candidates->take($limit)->values();
        }

        $fallback = Movie::query()
            ->published()
            ->with(['genres'])
            ->whereKeyNot($movie->id)
            ->whereNotIn('id', $candidates->pluck('id'))
            ->orderByDesc('published_at')
            ->limit($limit - $candidates->count())
            ->get();

        return $candidates->concat($fallback)->take($limit)->values();
    }

    /**
     * @return Collection<int, Series>
     */
    public function relatedSeries(Series $series, int $limit = 12): Collection
    {
        $genreIds = $series->genres()->pluck('genres.id');
        $rating = $series->filter_rating !== null ? (float) $series->filter_rating : null;
        $primaryGenre = $series->filter_primary_genre;
        $network = $series->filter_primary_network;
        $status = $series->status;

        $base = Series::query()
            ->published()
            ->with(['genres'])
            ->whereKeyNot($series->id);

        $candidates = $this->rankRelated(
            $base,
            $genreIds,
            $primaryGenre,
            $rating,
            null,
            $network,
            $limit,
            $status,
        );

        if ($candidates->count() >= $limit) {
            return $candidates->take($limit)->values();
        }

        $fallback = Series::query()
            ->published()
            ->with(['genres'])
            ->whereKeyNot($series->id)
            ->whereNotIn('id', $candidates->pluck('id'))
            ->orderByDesc('published_at')
            ->limit($limit - $candidates->count())
            ->get();

        return $candidates->concat($fallback)->take($limit)->values();
    }

    /**
     * @param  Builder<Movie>|Builder<Series>  $query
     * @return Collection<int, Movie|Series>
     */
    private function rankRelated(
        Builder $query,
        Collection $genreIds,
        ?string $primaryGenre,
        ?float $rating,
        ?string $country,
        ?string $network,
        int $limit,
        ?string $status = null,
    ): Collection {
        $builder = clone $query;

        if ($primaryGenre) {
            $builder->where('filter_primary_genre', $primaryGenre);
        }

        if ($genreIds->isNotEmpty()) {
            $builder->whereHas('genres', fn (Builder $genreQuery) => $genreQuery->whereIn('genres.id', $genreIds));
        }

        if ($rating !== null) {
            $builder->whereBetween('filter_rating', [$rating - 1.5, $rating + 1.5]);
        }

        if ($country) {
            $builder->where('origin_country', $country);
        }

        if ($network) {
            $builder->where('filter_primary_network', $network);
        }

        if ($status) {
            $builder->where('status', $status);
        }

        return $builder
            ->orderByDesc('filter_rating')
            ->orderByDesc('published_at')
            ->limit($limit)
            ->get();
    }
}

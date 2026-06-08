<?php

namespace App\Services\Cinema;

use App\GraphQL\Support\MediaUrlHelper;
use App\Models\Movie;
use App\Services\TmdbService;

/** Public movie payloads for catalog lists (GraphQL MovieCard). */
final class MoviePublicPresenter
{
    public function __construct(
        private readonly MediaUrlHelper $mediaUrlHelper,
        private readonly TmdbService $tmdbService,
    ) {}

    /**
     * @return array{poster_url: ?string, backdrop_url: ?string, genres: list<string>, rating: mixed}
     */
    public function listMeta(Movie $movie): array
    {
        $metadata = is_array($movie->metadata) ? $movie->metadata : [];

        return [
            'poster_url' => $this->mediaUrlHelper->resolveManagedOrExternalUrl(
                $movie->poster_path,
                data_get($metadata, 'tmdb.poster_url'),
            ),
            'backdrop_url' => $this->mediaUrlHelper->resolveManagedOrExternalUrl(
                null,
                data_get($metadata, 'tmdb.backdrop_url'),
            ),
            'genres' => $this->genres($movie),
            'rating' => data_get($metadata, 'tmdb.rating'),
        ];
    }

    /**
     * @return list<string>
     */
    public function genres(Movie $movie): array
    {
        $metadata = is_array($movie->metadata) ? $movie->metadata : [];
        $genres = data_get($metadata, 'tmdb.genres', []);

        return $this->tmdbService->localizeGenreNames(
            is_array($genres) ? $genres : [],
            'movie',
        );
    }
}

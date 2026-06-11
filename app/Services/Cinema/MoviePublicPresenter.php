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
        private readonly ImagePresentationService $imagePresentationService,
        private readonly TmdbService $tmdbService,
    ) {}

    /**
     * @return array{poster_url: ?string, backdrop_url: ?string, genres: list<string>, rating: mixed}
     */
    public function listMeta(Movie $movie): array
    {
        $metadata = is_array($movie->metadata) ? $movie->metadata : [];
        $posterImage = $this->imagePresentationService->moviePosterImage($metadata);
        $backdropImage = $this->imagePresentationService->movieBackdropImage($metadata);

        return [
            'poster_url' => $this->imagePresentationService->resolveMoviePosterUrl($movie->poster_path, $metadata),
            'poster_blur_url' => data_get($posterImage, 'blur'),
            'poster_srcset' => data_get($posterImage, 'srcset_string'),
            'poster_image' => $posterImage,
            'backdrop_url' => $this->imagePresentationService->resolveMovieBackdropUrl($metadata),
            'backdrop_blur_url' => data_get($backdropImage, 'blur'),
            'backdrop_srcset' => data_get($backdropImage, 'srcset_string'),
            'backdrop_image' => $backdropImage,
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

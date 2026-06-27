<?php

namespace App\Support;

use App\Models\Genre;
use App\Support\CatalogListPresentation;
use App\Services\Cinema\GenreCatalogService;
use App\Services\Cinema\ImagePresentationService;
use App\Services\Cinema\MediaPathService;
use App\Services\TmdbService;
use Illuminate\Support\Facades\DB;

class CatalogListResponseMapper
{
    public function __construct(
        private readonly MediaPathService $mediaPathService,
        private readonly ImagePresentationService $imagePresentationService,
        private readonly GenreCatalogService $genreCatalogService,
        private readonly TmdbService $tmdbService,
    ) {
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function adminMovieListItem(array $row): array
    {
        $createdById = isset($row['created_by']) ? (int) $row['created_by'] : null;

        return [
            'id' => (int) $row['id'],
            'title' => (string) ($row['title'] ?? ''),
            'slug' => (string) ($row['slug'] ?? ''),
            'synopsis' => $row['synopsis'] ?? null,
            'release_year' => isset($row['release_year']) ? (int) $row['release_year'] : null,
            'poster_url' => $this->mediaPathService->inferPublicUrlFromAbsolutePath($row['poster_path'] ?? null)
                ?: ($row['tmdb_poster_url'] ?? null),
            'has_dubbed_audio' => (bool) ($row['has_dubbed_audio'] ?? false),
            'has_subtitle' => (bool) ($row['has_subtitle'] ?? false),
            'publish_status' => (string) ($row['publish_status'] ?? 'draft'),
            'pipeline_status' => (string) ($row['pipeline_status'] ?? 'idle'),
            'processing_status' => (string) ($row['processing_status'] ?? 'pending'),
            'processing_error' => $row['processing_error'] ?? null,
            'created_at' => $this->isoTimestamp($row['created_at'] ?? null),
            'updated_at' => null,
            'created_by' => $createdById
                ? [
                    'id' => $createdById,
                    'name' => $row['created_by_name'] ?? null,
                    'email' => $row['created_by_email'] ?? null,
                ]
                : null,
            'filter_popularity' => isset($row['filter_popularity']) ? (float) $row['filter_popularity'] : null,
            'vote_count' => isset($row['vote_count']) ? (int) $row['vote_count'] : null,
            'trending_rank' => isset($row['trending_rank']) ? (int) $row['trending_rank'] : null,
            'internal_popularity_30d' => isset($row['internal_popularity_30d'])
                ? (int) $row['internal_popularity_30d']
                : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function adminSeriesListItem(array $row): array
    {
        $createdById = isset($row['created_by']) ? (int) $row['created_by'] : null;

        return [
            'id' => (int) $row['id'],
            'title' => (string) ($row['title'] ?? ''),
            'slug' => (string) ($row['slug'] ?? ''),
            'synopsis' => $row['synopsis'] ?? null,
            'first_air_date' => $this->dateString($row['first_air_date'] ?? null),
            'status' => $row['status'] ?? null,
            'network' => $row['network'] ?? null,
            'poster_url' => $row['poster_url'] ?? null,
            'has_subtitle' => (bool) ($row['has_subtitle'] ?? false),
            'has_dubbed' => (bool) ($row['has_dubbed'] ?? false),
            'is_featured' => (bool) ($row['is_featured'] ?? false),
            'publish_status' => (string) ($row['publish_status'] ?? 'draft'),
            'pipeline_status' => (string) ($row['pipeline_status'] ?? 'idle'),
            'seasons_count' => (int) ($row['seasons_count'] ?? 0),
            'episodes_count' => (int) ($row['episodes_count'] ?? 0),
            'created_at' => $this->isoTimestamp($row['created_at'] ?? null),
            'updated_at' => null,
            'created_by' => $createdById
                ? [
                    'id' => $createdById,
                    'name' => $row['created_by_name'] ?? null,
                    'email' => $row['created_by_email'] ?? null,
                ]
                : null,
            'filter_popularity' => isset($row['filter_popularity']) ? (float) $row['filter_popularity'] : null,
            'vote_count' => isset($row['vote_count']) ? (int) $row['vote_count'] : null,
            'trending_rank' => isset($row['trending_rank']) ? (int) $row['trending_rank'] : null,
            'internal_popularity_30d' => isset($row['internal_popularity_30d'])
                ? (int) $row['internal_popularity_30d']
                : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, array<int, array<string, mixed>>>  $genreMap
     * @return array<string, mixed>
     */
    public function publicMovieListItem(array $row, array $genreMap): array
    {
        $metadata = $this->decodeMetadata($row['metadata'] ?? null);
        $movieId = (int) $row['id'];
        $genres = collect($genreMap[$movieId] ?? [])
            ->map(fn (array $genre) => new Genre([
                'id' => $genre['id'],
                'name' => $genre['name'],
                'slug' => $genre['slug'] ?? null,
                'type' => $genre['type'] ?? 'movie',
            ]));

        $rating = CatalogListPresentation::listRating(
            isset($row['filter_rating']) ? (float) $row['filter_rating'] : null,
            $metadata,
        );

        $posterUrl = $this->imagePresentationService->resolveMoviePosterUrl(
            $row['poster_path'] ?? null,
            $metadata,
        );
        $backdropUrl = $this->imagePresentationService->resolveMovieBackdropUrl($metadata);
        $posterImage = $this->imagePresentationService->moviePosterImage($metadata);
        $backdropImage = $this->imagePresentationService->movieBackdropImage($metadata);

        return [
            'id' => $movieId,
            'title' => (string) ($row['title'] ?? ''),
            'slug' => (string) ($row['slug'] ?? ''),
            'synopsis' => $row['synopsis'] ?? null,
            'release_year' => isset($row['release_year']) ? (int) $row['release_year'] : null,
            'has_subtitle' => (bool) ($row['has_subtitle'] ?? false),
            'has_dubbed_audio' => (bool) ($row['has_dubbed_audio'] ?? false),
            'poster_url' => $posterUrl,
            'poster_blur_url' => data_get($posterImage, 'blur'),
            'poster_srcset' => data_get($posterImage, 'srcset_string'),
            'poster_image' => $posterImage,
            'backdrop_url' => $backdropUrl,
            'backdrop_blur_url' => data_get($backdropImage, 'blur'),
            'backdrop_srcset' => data_get($backdropImage, 'srcset_string'),
            'backdrop_image' => $backdropImage,
            'playback_quality' => CatalogListPresentation::playbackQualityLabel(null, $metadata),
            'rating' => $rating,
            'genres' => $this->genreCatalogService->serializeGenres($genres),
            'details' => [
                'genres' => CatalogListPresentation::posterGenreLine($genres),
                'rating' => $rating,
                'backdrop_url' => $backdropUrl,
            ],
        ];
    }

    /**
     * Slim carousel card DTO — genres included for hover overlay; no synopsis or backdrop payloads.
     *
     * @param  array<string, mixed>  $row
     * @param  array<int, array<int, array<string, mixed>>>  $genreMap
     * @return array<string, mixed>
     */
    public function carouselMovieItem(array $row, array $genreMap = []): array
    {
        $metadata = $this->decodeMetadata($row['metadata'] ?? null);
        $movieId = (int) $row['id'];
        $genres = collect($genreMap[$movieId] ?? [])
            ->map(fn (array $genre) => new Genre([
                'id' => $genre['id'],
                'name' => $genre['name'],
                'slug' => $genre['slug'] ?? null,
                'type' => $genre['type'] ?? 'movie',
            ]));

        $rating = CatalogListPresentation::listRating(
            isset($row['filter_rating']) ? (float) $row['filter_rating'] : null,
            $metadata,
        );

        $posterImage = $this->imagePresentationService->moviePosterImage($metadata);
        $posterUrl = $this->imagePresentationService->resolveMoviePosterUrl(
            $row['poster_path'] ?? null,
            $metadata,
        );

        return [
            'id' => $movieId,
            'slug' => (string) ($row['slug'] ?? ''),
            'title' => (string) ($row['title'] ?? ''),
            'release_year' => isset($row['release_year']) ? (int) $row['release_year'] : null,
            'filter_rating' => isset($row['filter_rating']) ? (float) $row['filter_rating'] : null,
            'rating' => $rating,
            'content_type' => 'movie',
            'poster_url' => $posterUrl,
            'poster_blur_url' => data_get($posterImage, 'blur'),
            'poster_srcset' => data_get($posterImage, 'srcset_string'),
            'poster_image' => $posterImage,
            'has_subtitle' => (bool) ($row['has_subtitle'] ?? false),
            'has_dubbed_audio' => (bool) ($row['has_dubbed_audio'] ?? false),
            'genres' => $this->genreCatalogService->serializeGenres($genres),
            'details' => [
                'genres' => CatalogListPresentation::posterGenreLine($genres),
                'rating' => $rating,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, array<int, array<string, mixed>>>  $genreMap
     * @return array<string, mixed>
     */
    public function carouselSeriesItem(array $row, array $genreMap = []): array
    {
        $metadata = $this->decodeMetadata($row['metadata'] ?? null);
        $seriesId = (int) $row['id'];
        $genres = collect($genreMap[$seriesId] ?? [])
            ->map(fn (array $genre) => new Genre([
                'id' => $genre['id'],
                'name' => $genre['name'],
                'slug' => $genre['slug'] ?? null,
                'type' => $genre['type'] ?? 'tv',
            ]));

        $rating = CatalogListPresentation::listRating(
            isset($row['filter_rating']) ? (float) $row['filter_rating'] : null,
            $metadata,
        );

        $posterImage = $this->imagePresentationService->seriesPosterImage($metadata);
        $posterUrl = $this->imagePresentationService->resolveSeriesPosterUrl(
            $row['poster_url'] ?? null,
            $metadata,
        );

        return [
            'id' => $seriesId,
            'slug' => (string) ($row['slug'] ?? ''),
            'title' => (string) ($row['title'] ?? ''),
            'release_year' => isset($row['release_year']) ? (int) $row['release_year'] : null,
            'filter_rating' => isset($row['filter_rating']) ? (float) $row['filter_rating'] : null,
            'rating' => $rating,
            'content_type' => 'series',
            'poster_url' => $posterUrl,
            'poster_blur_url' => data_get($posterImage, 'blur'),
            'poster_srcset' => data_get($posterImage, 'srcset_string'),
            'poster_image' => $posterImage,
            'has_subtitle' => (bool) ($row['has_subtitle'] ?? false),
            'has_dubbed' => (bool) ($row['has_dubbed'] ?? false),
            'genres' => $this->genreCatalogService->serializeGenres($genres),
            'details' => [
                'genres' => CatalogListPresentation::posterGenreLine($genres),
                'rating' => $rating,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, array<int, array<string, mixed>>>  $genreMap
     * @return array<string, mixed>
     */
    public function publicSeriesListItem(array $row, array $genreMap): array
    {
        $metadata = $this->decodeMetadata($row['metadata'] ?? null);
        $seriesId = (int) $row['id'];
        $genres = collect($genreMap[$seriesId] ?? [])
            ->map(fn (array $genre) => new Genre([
                'id' => $genre['id'],
                'name' => $genre['name'],
                'slug' => $genre['slug'] ?? null,
                'type' => $genre['type'] ?? 'tv',
            ]));

        $rating = CatalogListPresentation::listRating(
            isset($row['filter_rating']) ? (float) $row['filter_rating'] : null,
            $metadata,
        );

        $genreLine = $genres->isNotEmpty()
            ? CatalogListPresentation::posterGenreLine($genres)
            : $this->tmdbService->localizeGenreNames(
                data_get($metadata, 'tmdb.genres', []),
                'tv',
            );

        return [
            'id' => $seriesId,
            'title' => (string) ($row['title'] ?? ''),
            'slug' => (string) ($row['slug'] ?? ''),
            'original_title' => $row['original_title'] ?? null,
            'synopsis' => $row['synopsis'] ?? null,
            'release_year' => isset($row['release_year']) ? (int) $row['release_year'] : null,
            'status' => $row['status'] ?? null,
            'status_label_fa' => $this->translateProductionStatus($row['status'] ?? null),
            'poster_url' => $this->imagePresentationService->resolveSeriesPosterUrl($row['poster_url'] ?? null, $metadata),
            'poster_blur_url' => data_get($this->imagePresentationService->seriesPosterImage($metadata), 'blur'),
            'poster_srcset' => data_get($this->imagePresentationService->seriesPosterImage($metadata), 'srcset_string'),
            'poster_image' => $this->imagePresentationService->seriesPosterImage($metadata),
            'backdrop_url' => $this->imagePresentationService->resolveSeriesBackdropUrl($row['backdrop_url'] ?? null, $metadata),
            'backdrop_blur_url' => data_get($this->imagePresentationService->seriesBackdropImage($metadata), 'blur'),
            'backdrop_srcset' => data_get($this->imagePresentationService->seriesBackdropImage($metadata), 'srcset_string'),
            'backdrop_image' => $this->imagePresentationService->seriesBackdropImage($metadata),
            'network' => $row['network'] ?? null,
            'number_of_seasons' => isset($row['number_of_seasons']) ? (int) $row['number_of_seasons'] : null,
            'number_of_episodes' => isset($row['number_of_episodes']) ? (int) $row['number_of_episodes'] : null,
            'has_subtitle' => (bool) ($row['has_subtitle'] ?? false),
            'has_dubbed' => (bool) ($row['has_dubbed'] ?? false),
            'playback_quality' => CatalogListPresentation::playbackQualityLabel(null, $metadata),
            'rating' => $rating,
            'genres' => $this->genreCatalogService->serializeGenres($genres),
            'details' => [
                'rating' => $rating,
                'genres' => $genreLine,
                'backdrop_url' => $row['backdrop_url'] ?: data_get($metadata, 'tmdb.backdrop_url'),
            ],
        ];
    }

    /**
     * @param  array<int>  $movieIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function movieGenreMap(array $movieIds): array
    {
        if ($movieIds === []) {
            return [];
        }

        $map = [];

        $rows = DB::table('movie_genre as mg')
            ->join('genres as g', 'g.id', '=', 'mg.genre_id')
            ->whereIn('mg.movie_id', $movieIds)
            ->select('mg.movie_id', 'g.id', 'g.name', 'g.slug', 'g.type')
            ->get();

        foreach ($rows as $row) {
            $map[(int) $row->movie_id][] = [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'slug' => $row->slug,
                'type' => $row->type,
            ];
        }

        return $map;
    }

    /**
     * @param  array<int>  $seriesIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function seriesGenreMap(array $seriesIds): array
    {
        if ($seriesIds === []) {
            return [];
        }

        $map = [];

        $rows = DB::table('series_genre as sg')
            ->join('genres as g', 'g.id', '=', 'sg.genre_id')
            ->whereIn('sg.series_id', $seriesIds)
            ->select('sg.series_id', 'g.id', 'g.name', 'g.slug', 'g.type')
            ->get();

        foreach ($rows as $row) {
            $map[(int) $row->series_id][] = [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'slug' => $row->slug,
                'type' => $row->type,
            ];
        }

        return $map;
    }

    private function translateProductionStatus(?string $status): ?string
    {
        return match ($status) {
            'Returning Series' => 'در حال پخش',
            'Ended' => 'پایان یافته',
            'Canceled' => 'لغو شده',
            'In Production' => 'در حال تولید',
            'Pilot' => 'پایلوت',
            default => $status,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeMetadata(mixed $metadata): array
    {
        if (is_array($metadata)) {
            return $metadata;
        }

        if (is_string($metadata) && $metadata !== '') {
            $decoded = json_decode($metadata, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function isoTimestamp(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return \Carbon\Carbon::parse((string) $value)->toISOString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function dateString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return \Carbon\Carbon::parse((string) $value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}

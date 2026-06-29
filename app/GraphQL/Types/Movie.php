<?php

namespace App\GraphQL\Types;

use App\GraphQL\Support\MediaUrlHelper;
use App\Models\Movie as MovieModel;
use App\Models\MovieCast;
use App\Services\Cinema\GenreCatalogService;
use App\Services\Cinema\ImagePresentationService;
use App\Support\ContentMetadataOptions;
use App\Services\TmdbService;

final class Movie
{
    public function __construct(
        private readonly MediaUrlHelper $mediaUrlHelper,
        private readonly ImagePresentationService $imagePresentationService,
        private readonly TmdbService $tmdbService,
        private readonly GenreCatalogService $genreCatalogService,
    ) {}

    public function posterUrl(MovieModel $movie): ?string
    {
        $metadata = is_array($movie->metadata) ? $movie->metadata : [];

        return $this->imagePresentationService->resolveMoviePosterUrl($movie->poster_path, $metadata);
    }

    public function backdropUrl(MovieModel $movie): ?string
    {
        $metadata = is_array($movie->metadata) ? $movie->metadata : [];

        return $this->imagePresentationService->resolveMovieBackdropUrl($metadata);
    }

    /**
     * @return ?array<string, mixed>
     */
    public function posterImage(MovieModel $movie): ?array
    {
        $metadata = is_array($movie->metadata) ? $movie->metadata : [];

        return $this->imagePresentationService->moviePosterImage($metadata);
    }

    /**
     * @return ?array<string, mixed>
     */
    public function backdropImage(MovieModel $movie): ?array
    {
        $metadata = is_array($movie->metadata) ? $movie->metadata : [];

        return $this->imagePresentationService->movieBackdropImage($metadata);
    }

    public function posterBlurUrl(MovieModel $movie): ?string
    {
        return data_get($this->posterImage($movie), 'blur');
    }

    public function posterSrcset(MovieModel $movie): ?string
    {
        return data_get($this->posterImage($movie), 'srcset_string');
    }

    public function backdropBlurUrl(MovieModel $movie): ?string
    {
        return data_get($this->backdropImage($movie), 'blur');
    }

    public function backdropSrcset(MovieModel $movie): ?string
    {
        return data_get($this->backdropImage($movie), 'srcset_string');
    }

    public function trailerUrl(MovieModel $movie): ?string
    {
        $metadata = is_array($movie->metadata) ? $movie->metadata : [];

        return $this->mediaUrlHelper->resolveManagedOrExternalUrl(
            $movie->trailer_path,
            data_get($metadata, 'tmdb.trailer_url'),
        );
    }

    /**
     * @return list<string>
     */
    public function genres(MovieModel $movie): array
    {
        $movie->loadMissing('genres');

        if ($movie->genres->isNotEmpty()) {
            return $movie->genres->pluck('name')->values()->all();
        }

        $metadata = is_array($movie->metadata) ? $movie->metadata : [];
        $genres = data_get($metadata, 'tmdb.genres', []);

        return $this->tmdbService->localizeGenreNames(
            is_array($genres) ? $genres : [],
            'movie',
        );
    }

    /**
     * @return list<array{id: string, slug: string, name: string}>
     */
    public function genreTags(MovieModel $movie): array
    {
        $movie->loadMissing('genres');

        return collect($this->genreCatalogService->serializeGenres($movie->genres))
            ->map(fn (array $genre) => [
                'id' => (string) $genre['id'],
                'slug' => (string) $genre['slug'],
                'name' => (string) $genre['name'],
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{
     *   rating: ?float,
     *   original_language: ?string,
     *   directors: list<string>,
     *   writers: list<string>,
     *   genres: list<string>,
     *   languages: list<string>,
     *   countries: list<string>,
     *   network: ?string,
     *   runtime_minutes: ?int,
     *   actors: list<array{name: ?string, character: ?string, profile_url: ?string}>,
     *   gallery: list<string>
     * }
     */
    public function details(MovieModel $movie): array
    {
        $metadata = is_array($movie->metadata) ? $movie->metadata : [];

        return [
            'rating' => ($value = data_get($metadata, 'tmdb.rating')) !== null ? (float) $value : null,
            'original_language' => data_get($metadata, 'tmdb.original_language'),
            'directors' => collect(data_get($metadata, 'tmdb.directors', []))
                ->map(fn ($item) => trim((string) $item))
                ->filter()
                ->values()
                ->all(),
            'writers' => collect(data_get($metadata, 'tmdb.writers', []))
                ->map(fn ($item) => trim((string) $item))
                ->filter()
                ->values()
                ->all(),
            'genres' => $this->genres($movie),
            'languages' => collect(data_get($metadata, 'tmdb.languages', []))
                ->map(fn ($item) => trim((string) $item))
                ->filter()
                ->values()
                ->all(),
            'countries' => collect(data_get($metadata, 'tmdb.countries', []))
                ->map(fn ($item) => trim((string) $item))
                ->filter()
                ->values()
                ->all(),
            'network' => $movie->provider_name
                ?: data_get($metadata, 'tmdb.network')
                ?: collect(data_get($metadata, 'tmdb.production_companies', []))
                    ->map(fn ($company) => is_array($company) ? ($company['name'] ?? null) : $company)
                    ->filter()
                    ->first(),
            'runtime_minutes' => ($runtime = data_get($metadata, 'tmdb.runtime_minutes')) !== null ? (int) $runtime : null,
            // Cast read path: prefer normalized `movie_cast` rows; fall back to legacy metadata JSON
            // when SyncMoviePersonCreditsJob has not completed yet (see cinema:audit-cast-consistency).
            'actors' => $this->resolvePublicActors($movie, $metadata),
            'gallery' => collect([
                $this->mediaUrlHelper->resolveManagedOrExternalUrl(
                    null,
                    data_get($metadata, 'tmdb.backdrop_url'),
                ),
                $this->posterUrl($movie),
            ])
                ->filter(fn ($item) => filled($item))
                ->unique()
                ->values()
                ->all(),
        ];
    }

    /**
     * @return list<array{name: string, language: string, type: string}>
     */
    public function audioTracks(MovieModel $movie): array
    {
        return collect($movie->audio_tracks ?? [])
            ->map(fn (array $track) => [
                'name' => (string) ($track['label'] ?? $track['name'] ?? 'صوت'),
                'language' => (string) ($track['language'] ?? 'und'),
                'type' => (string) ($track['type'] ?? 'original'),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{playback: array{hls_url: ?string, downloads: list<array{quality: string, url: string, size_mb: ?int}>}}
     */
    public function metadataPlayback(MovieModel $movie): array
    {
        $metadata = is_array($movie->metadata) ? $movie->metadata : [];
        $playback = $metadata['playback'] ?? [];
        $downloads = collect(data_get($playback, 'downloads', []))
            ->filter(fn ($url, $quality) => filled($quality) && filled($url))
            ->map(fn ($url, $quality) => [
                'quality' => (string) $quality,
                'url' => (string) $url,
                'size_mb' => null,
            ])
            ->values()
            ->all();

        return [
            'playback' => [
                'hls_url' => $this->mediaUrlHelper->resolveMoviePlaybackUrl($movie),
                'downloads' => $downloads,
            ],
        ];
    }

    public function metaTitle(MovieModel $movie): ?string
    {
        $metadata = is_array($movie->metadata) ? $movie->metadata : [];

        return data_get($metadata, 'seo.meta_title');
    }

    public function metaDescription(MovieModel $movie): ?string
    {
        $metadata = is_array($movie->metadata) ? $movie->metadata : [];

        return data_get($metadata, 'seo.meta_description');
    }

    /**
     * @return list<string>
     */
    public function contentWarnings(MovieModel $movie): array
    {
        $metadata = is_array($movie->metadata) ? $movie->metadata : [];

        return ContentMetadataOptions::filterAllowedList(
            data_get($metadata, 'content_warnings'),
            ContentMetadataOptions::CONTENT_WARNINGS,
        );
    }

    /**
     * @return list<string>
     */
    public function qualityBadges(MovieModel $movie): array
    {
        $metadata = is_array($movie->metadata) ? $movie->metadata : [];

        return ContentMetadataOptions::filterAllowedList(
            data_get($metadata, 'quality_badges'),
            ContentMetadataOptions::QUALITY_BADGES,
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return list<array{name: ?string, character: ?string, profile_url: ?string, profile_image: mixed}>
     */
    private function resolvePublicActors(MovieModel $movie, array $metadata): array
    {
        $movie->loadMissing(['castMembers.person']);

        if ($movie->castMembers->isNotEmpty()) {
            return $movie->castMembers
                ->sortBy('cast_order')
                ->values()
                ->map(function (MovieCast $row) {
                    $person = $row->person;

                    return [
                        'name' => $person?->name,
                        'character' => $row->character_name,
                        'profile_url' => $person?->profile_url,
                        'profile_image' => null,
                    ];
                })
                ->all();
        }

        return collect(data_get($metadata, 'tmdb.actors', []))
            ->filter(fn ($actor) => is_array($actor))
            ->map(fn (array $actor) => [
                'name' => $actor['name'] ?? null,
                'character' => $actor['character'] ?? null,
                'profile_url' => $actor['profile_url'] ?? null,
                'profile_image' => is_array($actor['profile_image'] ?? null)
                    ? $actor['profile_image']
                    : null,
            ])
            ->values()
            ->all();
    }
}

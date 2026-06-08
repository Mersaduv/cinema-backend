<?php

namespace App\Http\Controllers;

use App\Models\Movie;
use App\Support\CatalogListPresentation;
use App\Support\CatalogListResponseMapper;
use App\Services\Cinema\CatalogCacheService;
use App\Services\Cinema\CatalogFilterQuery;
use App\Services\Cinema\CatalogListProcedureService;
use App\Services\Cinema\GenreCatalogService;
use App\Services\Cinema\MediaPathService;
use App\Services\Cinema\RelatedContentService;
use App\Services\TmdbService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class PublicMovieController extends Controller
{
    public function __construct(
        private readonly MediaPathService $mediaPathService,
        private readonly TmdbService $tmdbService,
        private readonly CatalogFilterQuery $catalogFilterQuery,
        private readonly GenreCatalogService $genreCatalogService,
        private readonly CatalogListProcedureService $catalogListProcedureService,
        private readonly CatalogListResponseMapper $catalogListResponseMapper,
        private readonly CatalogCacheService $catalogCacheService,
        private readonly RelatedContentService $relatedContentService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->integer('page', 1));
        $perPage = min(max((int) $request->integer('per_page', 24), 1), 48);
        $cacheKey = $this->catalogCacheService->listCacheKey(
            'movies_list',
            md5($request->getQueryString() ?? ''),
        );

        $payload = Cache::remember($cacheKey, 300, function () use ($request, $page, $perPage) {
            $params = $this->catalogFilterQuery->procedureParams($request, 'movies');

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
        });

        return response()
            ->json(['success' => true, 'data' => $payload])
            ->withHeaders([
                'Cache-Control' => 'public, max-age=300, stale-while-revalidate=60',
                'Vary' => 'Accept-Encoding',
            ]);
    }

    public function show(Movie $movie): JsonResponse
    {
        if ($movie->publish_status !== 'published') {
            return response()->json([
                'success' => false,
                'message' => 'Movie is not published.',
            ], 404);
        }

        $movie->load(['mediaAsset.variants', 'castMembers.person', 'crewMembers.person', 'genres']);

        if (config('app.debug')) {
            Log::info('Cinema public movie playback payload', [
                'movie_id' => $movie->id,
                'slug' => $movie->slug,
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'movie' => $this->transformMovie($movie),
            ],
        ]);
    }

    public function related(Request $request, Movie $movie): JsonResponse
    {
        if ($movie->publish_status !== 'published') {
            return response()->json(['success' => false, 'message' => 'Movie is not published.'], 404);
        }

        $limit = min(max((int) $request->integer('limit', 12), 1), 12);
        $cacheKey = 'related_movies_v1_'.$movie->id;

        $movies = Cache::remember($cacheKey, 3600, function () use ($movie, $limit) {
            return $this->relatedContentService
                ->relatedMovies($movie, $limit)
                ->map(fn (Movie $item) => $this->transformMovieListItem($item))
                ->values()
                ->all();
        });

        return response()
            ->json([
                'success' => true,
                'data' => ['movies' => $movies],
            ])
            ->header('Cache-Control', 'public, max-age=3600');
    }

    private function transformMovieListItem(Movie $movie): array
    {
        $metadata = is_array($movie->metadata) ? $movie->metadata : [];

        $rating = CatalogListPresentation::listRating(
            $movie->filter_rating !== null ? (float) $movie->filter_rating : null,
            $metadata,
        );

        return [
            'id' => $movie->id,
            'title' => $movie->title,
            'slug' => $movie->slug,
            'synopsis' => $movie->synopsis,
            'release_year' => $movie->release_year,
            'has_subtitle' => $movie->has_subtitle,
            'has_dubbed_audio' => $movie->has_dubbed_audio,
            'poster_url' => $this->resolveManagedOrExternalUrl($movie->poster_path, data_get($metadata, 'tmdb.poster_url')),
            'backdrop_url' => $this->resolveManagedOrExternalUrl(null, data_get($metadata, 'tmdb.backdrop_url')),
            'playback_quality' => CatalogListPresentation::playbackQualityLabel($movie->mediaAsset, $metadata),
            'rating' => $rating,
            'genres' => $this->genreCatalogService->serializeGenres($movie->genres),
            'details' => [
                'genres' => CatalogListPresentation::posterGenreLine($movie->genres),
                'rating' => $rating,
                'backdrop_url' => data_get($metadata, 'tmdb.backdrop_url'),
            ],
        ];
    }

    private function transformMovie(Movie $movie): array
    {
        $mediaAsset = $movie->mediaAsset;
        $metadata = is_array($movie->metadata) ? $movie->metadata : [];
        $playbackUrl = $this->resolvePlaybackUrl($movie);
        $subtitleUrl = $this->resolveManagedOrExternalUrl($movie->subtitle_source_path);
        $normalizedGenres = $this->genreCatalogService->serializeGenres($movie->genres);

        $producerLabel = $movie->provider_name
            ?: data_get($metadata, 'tmdb.network')
            ?: collect(data_get($metadata, 'tmdb.production_companies', []))
                ->map(fn ($company) => is_array($company) ? ($company['name'] ?? null) : $company)
                ->filter()
                ->first();

        $tmdbMetadata = [
            'backdrop_url' => data_get($metadata, 'tmdb.backdrop_url'),
            'network' => $producerLabel,
            'rating' => data_get($metadata, 'tmdb.rating'),
            'original_language' => data_get($metadata, 'tmdb.original_language'),
            'runtime_minutes' => data_get($metadata, 'tmdb.runtime_minutes'),
            'directors' => data_get($metadata, 'tmdb.directors', []),
            'writers' => data_get($metadata, 'tmdb.writers', []),
            'genres' => $movie->genres->isNotEmpty()
                ? $movie->genres->pluck('name')->all()
                : $this->tmdbService->localizeGenreNames(data_get($metadata, 'tmdb.genres', []), 'movie'),
            'languages' => data_get($metadata, 'tmdb.languages', []),
            'countries' => data_get($metadata, 'tmdb.countries', []),
            'actors' => collect(data_get($metadata, 'tmdb.actors', []))
                ->filter(fn ($actor) => is_array($actor))
                ->map(fn (array $actor) => [
                    'name' => $actor['name'] ?? null,
                    'character' => $actor['character'] ?? null,
                    'profile_url' => $actor['profile_url'] ?? null,
                ])
                ->values()
                ->all(),
            'gallery' => $this->buildGalleryItems($movie, $metadata),
        ];

        return [
            'id' => $movie->id,
            'title' => $movie->title,
            'slug' => $movie->slug,
            'tmdb_id' => $movie->tmdb_id,
            'original_title' => $movie->original_title,
            'synopsis' => $movie->synopsis,
            'release_year' => $movie->release_year,
            'origin_country' => $movie->origin_country,
            'original_language' => $movie->original_language,
            'content_rating' => $movie->content_rating ?? $movie->age_rating,
            'duration_seconds' => $movie->duration_seconds,
            'provider_name' => $movie->provider_name,
            'poster_url' => $this->resolveManagedOrExternalUrl($movie->poster_path, data_get($metadata, 'tmdb.poster_url')),
            'trailer_url' => $this->resolveManagedOrExternalUrl($movie->trailer_path, data_get($metadata, 'tmdb.trailer_url')),
            'has_dubbed_audio' => $movie->has_dubbed_audio,
            'audio_tracks' => collect($movie->audio_tracks ?? [])
                ->map(fn (array $track) => [
                    'label' => $track['label'] ?? 'صوت اضافه',
                    'language' => $track['language'] ?? 'und',
                ])
                ->values()
                ->all(),
            'has_subtitle' => $movie->has_subtitle,
            'subtitle_source_url' => $subtitleUrl,
            'age_rating' => $movie->age_rating,
            'genres' => $normalizedGenres,
            'cast_members' => $movie->cast_members ?? [],
            'cast' => $movie->relationLoaded('castMembers')
                ? $movie->castMembers
                    ->sortBy('cast_order')
                    ->values()
                    ->map(fn ($row) => [
                        'person_id' => $row->person_id,
                        'name' => $row->person?->name,
                        'slug' => $row->person?->slug,
                        'character' => $row->character_name,
                        'profile_url' => $row->person?->getProfileImageUrl(),
                    ])
                    ->values()
                    ->all()
                : [],
            'crew' => $movie->relationLoaded('crewMembers')
                ? $movie->crewMembers
                    ->map(fn ($row) => [
                        'person_id' => $row->person_id,
                        'name' => $row->person?->name,
                        'slug' => $row->person?->slug,
                        'job' => $row->job,
                        'department' => $row->department,
                        'profile_url' => $row->person?->getProfileImageUrl(),
                    ])
                    ->values()
                    ->all()
                : [],
            'publish_status' => $movie->publish_status,
            'details' => $tmdbMetadata,
            'download_links' => $this->resolveMovieDownloadLinks($metadata),
            'playback' => $playbackUrl ? [
                'master_playlist_url' => $playbackUrl,
                'subtitle_url' => $subtitleUrl,
                'audio_tracks' => $this->resolvePlaybackAudioTracks($movie),
                'variants' => $mediaAsset?->variants
                    ? $mediaAsset->variants
                        ->sortBy('bandwidth')
                        ->values()
                        ->map(function ($variant) {
                            return [
                                'profile_key' => $variant->profile_key,
                                'resolution' => $variant->resolution,
                                'bandwidth' => $variant->bandwidth,
                            ];
                        })
                        ->all()
                    : [],
            ] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, string>
     */
    private function resolveMovieDownloadLinks(array $metadata): array
    {
        $stored = data_get($metadata, 'playback.downloads', []);

        if (! is_array($stored) || $stored === []) {
            return [];
        }

        $normalized = collect($stored)
            ->filter(fn ($url, $quality) => filled($quality) && filled($url))
            ->mapWithKeys(fn ($url, $quality) => [(string) $quality => (string) $url])
            ->all();

        if ($normalized === [] || $this->storedDownloadsLookLikeProbePlaceholders($normalized)) {
            return [];
        }

        return $normalized;
    }

    /**
     * @param  array<string, string>  $links
     */
    private function storedDownloadsLookLikeProbePlaceholders(array $links): bool
    {
        foreach ($links as $url) {
            $path = strtolower((string) (parse_url((string) $url, PHP_URL_PATH) ?? ''));

            if (preg_match('#/(?:source|movie|download|full)\.mp4$#', $path) === 1) {
                return true;
            }
        }

        return false;
    }

    private function resolvePlaybackAudioTracks(Movie $movie): array
    {
        $tracks = [[
            'key' => 'original',
            'label' => 'زبان اصلی',
            'language' => 'und',
            'is_default' => true,
        ]];

        if ($movie->has_dubbed_audio && filled($movie->dubbed_source_path)) {
            $tracks[] = [
                'key' => 'dubbed',
                'label' => 'دوبله فارسی',
                'language' => 'fa',
                'is_default' => false,
            ];
        }

        foreach (collect($movie->audio_tracks ?? [])->values() as $index => $track) {
            if (! filled($track['source_path'] ?? null)) {
                continue;
            }

            $tracks[] = [
                'key' => 'track-'.($index + 2),
                'label' => trim((string) ($track['label'] ?? ('صوت '.($index + 2)))) ?: ('صوت '.($index + 2)),
                'language' => trim((string) ($track['language'] ?? 'und')) ?: 'und',
                'is_default' => false,
            ];
        }

        return $tracks;
    }

    private function buildGalleryItems(Movie $movie, array $metadata): array
    {
        return collect([
            data_get($metadata, 'tmdb.backdrop_url'),
            $this->resolveManagedOrExternalUrl($movie->poster_path, data_get($metadata, 'tmdb.poster_url')),
        ])
            ->filter(fn ($value) => filled($value))
            ->unique()
            ->values()
            ->all();
    }

    private function resolveManagedOrExternalUrl(?string $path, ?string $externalUrl = null): ?string
    {
        if ($path && preg_match('#^https?://#i', $path) === 1) {
            return $path;
        }

        return $this->mediaPathService->inferPublicUrlFromAbsolutePath($path) ?: $externalUrl;
    }

    private function resolvePlaybackUrl(Movie $movie): ?string
    {
        $metadata = is_array($movie->metadata) ? $movie->metadata : [];
        $manualUrl = data_get($metadata, 'playback.hls_url');

        if (filled($manualUrl)) {
            return (string) $manualUrl;
        }

        $playlistPath = $movie->mediaAsset?->master_playlist_relative_path;

        if (! $playlistPath || $movie->processing_status !== 'ready') {
            return null;
        }

        if (preg_match('#^https?://#i', $playlistPath) === 1) {
            return $playlistPath;
        }

        return $this->mediaPathService->toPublicUrl($playlistPath);
    }
}

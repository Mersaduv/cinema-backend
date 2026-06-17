<?php

namespace App\Http\Controllers;

use App\Models\Series;
use App\Support\CatalogListPresentation;
use App\Support\CatalogListResponseMapper;
use App\Models\SeriesCast;
use App\Models\SeriesCrew;
use App\Models\SeriesEpisode;
use App\Models\SeriesSeason;
use App\Services\Cinema\CatalogCacheService;
use App\Services\Cinema\CatalogFilterQuery;
use App\Services\Cinema\CatalogListProcedureService;
use App\Services\Cinema\GenreCatalogService;
use App\Services\Cinema\ImagePresentationService;
use App\Services\Cinema\RelatedContentService;
use App\Services\Cinema\SeriesHlsPathService;
use App\Services\TmdbService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class PublicSeriesController extends Controller
{
    /** @var array<string, bool> */
    private array $hlsAvailabilityCache = [];

    public function __construct(
        private readonly SeriesHlsPathService $seriesHlsPathService,
        private readonly TmdbService $tmdbService,
        private readonly CatalogFilterQuery $catalogFilterQuery,
        private readonly GenreCatalogService $genreCatalogService,
        private readonly CatalogListProcedureService $catalogListProcedureService,
        private readonly CatalogListResponseMapper $catalogListResponseMapper,
        private readonly CatalogCacheService $catalogCacheService,
        private readonly RelatedContentService $relatedContentService,
        private readonly ImagePresentationService $imagePresentationService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->integer('page', 1));
        $perPage = min(max((int) $request->integer('per_page', 24), 1), 48);
        $cacheKey = $this->catalogCacheService->listCacheKey(
            'series_list',
            md5($request->getQueryString() ?? ''),
        );

        $payload = Cache::remember($cacheKey, 300, function () use ($request, $page, $perPage) {
            $params = $this->catalogFilterQuery->procedureParams($request, 'series');

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
                $perPage,
            );

            $seriesIds = array_map(fn (array $row) => (int) $row['id'], $result['rows']);
            $genreMap = $this->catalogListResponseMapper->seriesGenreMap($seriesIds);

            $series = array_map(
                fn (array $row) => $this->catalogListResponseMapper->publicSeriesListItem($row, $genreMap),
                $result['rows'],
            );

            $lastPage = max(1, (int) ceil($result['total'] / $perPage));

            return [
                'series' => $series,
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

    public function related(Request $request, Series $series): JsonResponse
    {
        if ($series->publish_status !== 'published') {
            return response()->json(['success' => false, 'message' => 'Series is not published.'], 404);
        }

        $limit = min(max((int) $request->integer('limit', 12), 1), 12);
        $cacheKey = 'related_series_v1_'.$series->id;

        $items = Cache::remember($cacheKey, 3600, function () use ($series, $limit) {
            return $this->relatedContentService
                ->relatedSeries($series, $limit)
                ->map(fn (Series $item) => $this->transformSeriesListItem($item))
                ->values()
                ->all();
        });

        return response()
            ->json([
                'success' => true,
                'data' => ['series' => $items],
            ])
            ->header('Cache-Control', 'public, max-age=3600');
    }

    public function show(Request $request, Series $series): JsonResponse
    {
        if ($series->publish_status !== 'published') {
            return response()->json([
                'success' => false,
                'message' => 'Series is not published.',
            ], 404);
        }

        if ($request->boolean('lite')) {
            $series->load(['castMembers.person', 'crewMembers.person', 'genres']);

            return response()->json([
                'success' => true,
                'data' => [
                    'series' => $this->transformSeriesOverview($series),
                ],
            ]);
        }

        $series->load([
            'seasons.episodes',
            'castMembers.person',
            'crewMembers.person',
            'genres',
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'series' => $this->transformSeries($series),
            ],
        ]);
    }

    public function episodes(Series $series): JsonResponse
    {
        if ($series->publish_status !== 'published') {
            return response()->json([
                'success' => false,
                'message' => 'Series is not published.',
            ], 404);
        }

        $series->load(['seasons.episodes']);

        $metadata = is_array($series->metadata) ? $series->metadata : [];
        $seriesHlsBase = data_get($metadata, 'playback.hls_base_url');

        return response()->json([
            'success' => true,
            'data' => [
                'seasons' => $series->seasons
                    ->map(fn (SeriesSeason $season) => $this->transformSeason($season, $seriesHlsBase))
                    ->filter(fn (array $season) => ($season['episodes'] ?? []) !== [])
                    ->values()
                    ->all(),
            ],
        ]);
    }

    private function transformSeriesListItem(Series $series): array
    {
        $metadata = is_array($series->metadata) ? $series->metadata : [];
        $posterImage = $this->imagePresentationService->seriesPosterImage($metadata);
        $backdropImage = $this->imagePresentationService->seriesBackdropImage($metadata);

        $rating = CatalogListPresentation::listRating(
            $series->filter_rating !== null ? (float) $series->filter_rating : null,
            $metadata,
        );

        return [
            'id' => $series->id,
            'title' => $series->title,
            'slug' => $series->slug,
            'original_title' => $series->original_title,
            'synopsis' => $series->synopsis,
            'release_year' => $series->release_year,
            'status' => $series->status,
            'status_label_fa' => $this->translateProductionStatus($series->status),
            'poster_url' => $this->imagePresentationService->resolveSeriesPosterUrl($series->poster_url, $metadata),
            'poster_blur_url' => data_get($posterImage, 'blur'),
            'poster_srcset' => data_get($posterImage, 'srcset_string'),
            'poster_image' => $posterImage,
            'backdrop_url' => $this->imagePresentationService->resolveSeriesBackdropUrl($series->backdrop_url, $metadata),
            'backdrop_blur_url' => data_get($backdropImage, 'blur'),
            'backdrop_srcset' => data_get($backdropImage, 'srcset_string'),
            'backdrop_image' => $backdropImage,
            'network' => $series->network,
            'number_of_seasons' => $series->number_of_seasons,
            'number_of_episodes' => $series->number_of_episodes,
            'has_subtitle' => $this->seriesHasPlayableEpisodeAttribute($series, 'subtitle_url'),
            'has_dubbed' => $this->seriesHasPlayableEpisodeAttribute($series, 'dubbed_audio_url'),
            'playback_quality' => CatalogListPresentation::playbackQualityLabel(null, $metadata),
            'rating' => $rating,
            'genres' => $this->genreCatalogService->serializeGenres($series->genres),
            'details' => [
                'rating' => $rating,
                'genres' => $series->genres->isNotEmpty()
                    ? CatalogListPresentation::posterGenreLine($series->genres)
                    : $this->tmdbService->localizeGenreNames(
                        data_get($metadata, 'tmdb.genres', []),
                        'tv',
                    ),
                'backdrop_url' => $series->backdrop_url ?: data_get($metadata, 'tmdb.backdrop_url'),
            ],
        ];
    }

    private function transformSeriesOverview(Series $series): array
    {
        $payload = $this->transformSeries($series, includeSeasons: false);
        $payload['seasons'] = [];

        return $payload;
    }

    private function transformSeries(Series $series, bool $includeSeasons = true): array
    {
        $metadata = is_array($series->metadata) ? $series->metadata : [];
        $seriesHlsBase = data_get($metadata, 'playback.hls_base_url');
        $normalizedGenres = $this->genreCatalogService->serializeGenres($series->genres);
        $posterImage = $this->imagePresentationService->seriesPosterImage($metadata);
        $backdropImage = $this->imagePresentationService->seriesBackdropImage($metadata);

        return [
            'id' => $series->id,
            'title' => $series->title,
            'slug' => $series->slug,
            'tmdb_id' => $series->tmdb_id,
            'original_title' => $series->original_title,
            'synopsis' => $series->synopsis,
            'first_air_date' => $series->first_air_date?->toDateString(),
            'last_air_date' => $series->last_air_date?->toDateString(),
            'release_year' => $series->release_year,
            'origin_country' => $series->origin_country,
            'original_language' => $series->original_language,
            'content_rating' => $series->content_rating ?? $series->age_rating,
            'status' => $series->status,
            'status_label_fa' => $this->translateProductionStatus($series->status),
            'episode_runtime_minutes' => $series->episode_runtime_minutes,
            'number_of_seasons' => $series->number_of_seasons,
            'number_of_episodes' => $series->number_of_episodes,
            'network' => $series->network,
            'provider_name' => $series->provider_name,
            'poster_url' => $this->imagePresentationService->resolveSeriesPosterUrl($series->poster_url, $metadata),
            'poster_blur_url' => data_get($posterImage, 'blur'),
            'poster_srcset' => data_get($posterImage, 'srcset_string'),
            'poster_image' => $posterImage,
            'backdrop_url' => $this->imagePresentationService->resolveSeriesBackdropUrl($series->backdrop_url, $metadata),
            'backdrop_blur_url' => data_get($backdropImage, 'blur'),
            'backdrop_srcset' => data_get($backdropImage, 'srcset_string'),
            'backdrop_image' => $backdropImage,
            'trailer_url' => $series->trailer_url,
            'age_rating' => $series->age_rating,
            'genres' => $normalizedGenres,
            'has_subtitle' => $includeSeasons
                ? $this->seriesHasPlayableEpisodeAttribute($series, 'subtitle_url')
                : $series->episodes()->whereNotNull('subtitle_url')->where('subtitle_url', '!=', '')->exists(),
            'has_dubbed' => $includeSeasons
                ? $this->seriesHasPlayableEpisodeAttribute($series, 'dubbed_audio_url')
                : $series->episodes()->whereNotNull('dubbed_audio_url')->where('dubbed_audio_url', '!=', '')->exists(),
            'cast' => $series->castMembers
                ->sortBy('cast_order')
                ->values()
                ->map(fn (SeriesCast $row) => [
                    'person_id' => $row->person_id,
                    'name' => $row->person?->name,
                    'slug' => $row->person?->slug,
                    'character' => $row->character_name,
                    'profile_url' => $row->person
                        ? $this->imagePresentationService->resolvePersonProfileUrl($row->person)
                        : null,
                    'profile_blur_url' => data_get(
                        $row->person ? $this->imagePresentationService->personProfileImage($row->person) : null,
                        'blur',
                    ),
                    'profile_srcset' => data_get(
                        $row->person ? $this->imagePresentationService->personProfileImage($row->person) : null,
                        'srcset_string',
                    ),
                    'profile_image' => $row->person
                        ? $this->imagePresentationService->personProfileImage($row->person)
                        : null,
                    'order' => $row->cast_order,
                ])
                ->all(),
            'crew' => $series->crewMembers
                ->map(fn (SeriesCrew $row) => [
                    'person_id' => $row->person_id,
                    'name' => $row->person?->name,
                    'slug' => $row->person?->slug,
                    'job' => $row->job,
                    'department' => $row->department,
                    'profile_url' => $row->person
                        ? $this->imagePresentationService->resolvePersonProfileUrl($row->person)
                        : null,
                    'profile_blur_url' => data_get(
                        $row->person ? $this->imagePresentationService->personProfileImage($row->person) : null,
                        'blur',
                    ),
                    'profile_srcset' => data_get(
                        $row->person ? $this->imagePresentationService->personProfileImage($row->person) : null,
                        'srcset_string',
                    ),
                    'profile_image' => $row->person
                        ? $this->imagePresentationService->personProfileImage($row->person)
                        : null,
                ])
                ->values()
                ->all(),
            'seasons' => $includeSeasons
                ? $series->seasons
                    ->map(fn (SeriesSeason $season) => $this->transformSeason($season, $seriesHlsBase))
                    ->filter(fn (array $season) => ($season['episodes'] ?? []) !== [])
                    ->values()
                    ->all()
                : [],
            'details' => [
                'backdrop_url' => $series->backdrop_url,
                'network' => $series->provider_name ?: $series->network ?: data_get($metadata, 'tmdb.network'),
                'rating' => data_get($metadata, 'tmdb.rating'),
                'original_language' => data_get($metadata, 'tmdb.original_language'),
                'creators' => data_get($metadata, 'tmdb.creators', []),
                'directors' => data_get($metadata, 'tmdb.directors', []),
                'writers' => data_get($metadata, 'tmdb.writers', []),
                'genres' => $series->genres->isNotEmpty()
                    ? $series->genres->pluck('name')->all()
                    : $this->tmdbService->localizeGenreNames(
                        data_get($metadata, 'tmdb.genres', []),
                        'tv',
                    ),
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
                'gallery' => $this->buildGalleryItems($series, $metadata),
            ],
        ];
    }

    private function transformSeason(SeriesSeason $season, ?string $seriesHlsBase): array
    {
        $seasonMetadata = is_array($season->metadata) ? $season->metadata : [];
        $seasonHlsBase = data_get($seasonMetadata, 'playback.hls_base_url');
        $posterFields = $this->imagePresentationService->enrichListImageFields(
            $season->poster_url,
            data_get($seasonMetadata, 'images.poster'),
        );

        return [
            'id' => $season->id,
            'season_number' => $season->season_number,
            'name' => $season->name,
            'synopsis' => $season->synopsis,
            'air_date' => $season->air_date?->toDateString(),
            'episode_count' => $season->episode_count,
            'poster_url' => $posterFields['url'],
            'poster_blur_url' => $posterFields['blur_url'],
            'poster_srcset' => $posterFields['srcset'],
            'poster_image' => $posterFields['image'],
            'episodes' => $season->episodes
                ->filter(fn (SeriesEpisode $episode) => $this->episodeIsPubliclyPlayable(
                    $episode,
                    $seriesHlsBase,
                    $seasonHlsBase,
                ))
                ->map(fn (SeriesEpisode $episode) => $this->transformEpisode(
                    $episode,
                    $seriesHlsBase,
                    $seasonHlsBase,
                ))
                ->values()
                ->all(),
        ];
    }

    private function transformEpisode(
        SeriesEpisode $episode,
        ?string $seriesHlsBase,
        ?string $seasonHlsBase,
    ): array {
        $resolved = $this->seriesHlsPathService->resolveEpisodeHlsUrl(
            $seriesHlsBase,
            $seasonHlsBase,
            $episode->hls_url,
            $episode->season_number,
            $episode->episode_number,
        );

        $hlsUrl = $resolved['hls_url'] ?? null;
        $episodeMetadata = is_array($episode->metadata) ? $episode->metadata : [];
        $stillFields = $this->imagePresentationService->enrichListImageFields(
            $episode->still_url,
            data_get($episodeMetadata, 'images.still'),
        );

        return [
            'id' => $episode->id,
            'season_number' => $episode->season_number,
            'episode_number' => $episode->episode_number,
            'title' => $episode->title,
            'slug' => $episode->slug,
            'synopsis' => $episode->synopsis,
            'air_date' => $episode->air_date?->toDateString(),
            'duration_minutes' => $episode->duration_minutes,
            'still_url' => $stillFields['url'],
            'still_blur_url' => $stillFields['blur_url'],
            'still_srcset' => $stillFields['srcset'],
            'still_image' => $stillFields['image'],
            'hls_url' => $hlsUrl,
            'dash_url' => filled($episode->dash_url) && (bool) ($episode->dash_verified ?? false)
                ? (string) $episode->dash_url
                : null,
            'download_links' => $this->resolveEpisodeDownloadLinks($episode),
            'subtitle_url' => $episode->subtitle_url,
            'dubbed_audio_url' => $this->resolveExternalDubbedAudioUrl($episode),
        ];
    }

    private function resolveExternalDubbedAudioUrl(SeriesEpisode $episode): ?string
    {
        $path = $episode->dubbed_audio_url;

        if (! filled($path)) {
            return null;
        }

        if (preg_match('#\.m3u8(\?.*)?$#i', (string) $path) === 1) {
            return null;
        }

        return (string) $path;
    }

    /**
     * @return array<string, string>
     */
    private function resolveEpisodeDownloadLinks(SeriesEpisode $episode): array
    {
        $stored = data_get($episode->metadata, 'playback.downloads', []);

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

    private function episodeIsPubliclyPlayable(
        SeriesEpisode $episode,
        ?string $seriesHlsBase,
        ?string $seasonHlsBase,
    ): bool {
        if (data_get($episode->metadata, 'playback.hls_verified') === false) {
            return false;
        }

        $resolved = $this->seriesHlsPathService->resolveEpisodeHlsUrl(
            $seriesHlsBase,
            $seasonHlsBase,
            $episode->hls_url,
            $episode->season_number,
            $episode->episode_number,
        );

        $url = $resolved['hls_url'] ?? null;

        if (! filled($url)) {
            return false;
        }

        if (data_get($episode->metadata, 'playback.hls_verified') === true) {
            return true;
        }

        if (filled($episode->hls_url)) {
            return true;
        }

        return false;
    }

    private function seriesHasPlayableEpisodeAttribute(Series $series, string $column): bool
    {
        $metadata = is_array($series->metadata) ? $series->metadata : [];
        $seriesHlsBase = data_get($metadata, 'playback.hls_base_url');

        return $series->episodes()
            ->with('season')
            ->get()
            ->contains(function (SeriesEpisode $episode) use ($seriesHlsBase, $column) {
                $seasonMetadata = is_array($episode->season?->metadata) ? $episode->season->metadata : [];
                $seasonHlsBase = data_get($seasonMetadata, 'playback.hls_base_url');

                if (! $this->episodeIsPubliclyPlayable($episode, $seriesHlsBase, $seasonHlsBase)) {
                    return false;
                }

                $value = $episode->{$column};

                return filled($value);
            });
    }

    private function buildGalleryItems(Series $series, array $metadata): array
    {
        return collect([
            $series->backdrop_url,
            $series->poster_url,
            data_get($metadata, 'tmdb.backdrop_url'),
        ])
            ->filter(fn ($value) => filled($value))
            ->unique()
            ->values()
            ->all();
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
}

<?php

namespace App\Http\Controllers;

use App\Jobs\SyncSeriesPersonCreditsJob;
use App\Models\Series;
use App\Services\Cinema\AdminCatalogSummaryService;
use App\Services\Cinema\AdminReferenceOptionsService;
use App\Services\Cinema\CatalogCacheService;
use App\Services\Cinema\CatalogListProcedureService;
use App\Services\Cinema\GenreCatalogService;
use App\Support\CatalogListResponseMapper;
use App\Models\SeriesCast;
use App\Models\SeriesCrew;
use App\Models\SeriesEpisode;
use App\Models\SeriesSeason;
use App\Services\Cinema\PublishScheduleService;
use App\Services\Cinema\SeriesHlsPathService;
use App\Support\CinemaSynopsisHelper;
use App\Services\TmdbService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdminSeriesController extends Controller
{
    public function __construct(
        private readonly SeriesHlsPathService $seriesHlsPathService,
        private readonly TmdbService $tmdbService,
        private readonly CinemaSynopsisHelper $synopsisHelper,
        private readonly PublishScheduleService $publishScheduleService,
        private readonly GenreCatalogService $genreCatalogService,
        private readonly AdminReferenceOptionsService $adminReferenceOptionsService,
        private readonly AdminCatalogSummaryService $adminCatalogSummaryService,
        private readonly CatalogListProcedureService $catalogListProcedureService,
        private readonly CatalogListResponseMapper $catalogListResponseMapper,
        private readonly CatalogCacheService $catalogCacheService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->integer('page', 1));
        $search = trim((string) $request->string('search'));
        $publishStatus = (string) $request->string('publish_status');
        $status = (string) $request->string('status');
        $perPage = min(max((int) $request->integer('per_page', 12), 1), 50);
        $includeSeasons = $request->boolean('include_seasons');
        $includeEpisodes = $request->boolean('include_episodes');

        if ($includeSeasons) {
            return $this->indexWithSeasons(
                $request,
                $search,
                $publishStatus,
                $status,
                $perPage,
                $includeEpisodes,
            );
        }

        $searchParam = $search !== '' ? $search : null;
        $publishFilter = in_array($publishStatus, ['draft', 'scheduled', 'published'], true)
            ? $publishStatus
            : null;
        $statusFilter = $status !== '' ? $status : null;

        $cacheKey = $this->catalogCacheService->listCacheKey(
            'admin_series_list',
            md5(serialize([
                $page,
                $perPage,
                $searchParam,
                $publishFilter,
                $statusFilter,
            ])),
        );

        $data = Cache::remember($cacheKey, 60, function () use (
            $searchParam,
            $publishFilter,
            $statusFilter,
            $page,
            $perPage,
        ): array {
            $result = $this->catalogListProcedureService->adminSeriesList(
                $searchParam,
                $publishFilter,
                $statusFilter,
                $page,
                $perPage,
            );

            $series = array_map(
                fn (array $row) => $this->catalogListResponseMapper->adminSeriesListItem($row),
                $result['rows'],
            );

            $lastPage = max(1, (int) ceil($result['total'] / $perPage));

            return [
                'series' => $series,
                'summary' => $result['summary'],
                'pagination' => [
                    'current_page' => $page,
                    'last_page' => $lastPage,
                    'per_page' => $perPage,
                    'total' => $result['total'],
                ],
            ];
        });

        return response()
            ->json(['success' => true, 'data' => $data])
            ->header('Cache-Control', 'no-store, private');
    }

    private function indexWithSeasons(
        Request $request,
        string $search,
        string $publishStatus,
        string $status,
        int $perPage,
        bool $includeEpisodes,
    ): JsonResponse {
        $query = Series::query()
            ->with(['creator'])
            ->when($includeEpisodes, fn ($query) => $query->with(['seasons.episodes']))
            ->when(! $includeEpisodes, fn ($query) => $query->with(['seasons']))
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($innerQuery) use ($search) {
                    $innerQuery
                        ->where('title', 'like', "%{$search}%")
                        ->orWhere('original_title', 'like', "%{$search}%")
                        ->orWhere('slug', 'like', "%{$search}%")
                        ->orWhere('network', 'like', "%{$search}%");
                });
            })
            ->when(in_array($publishStatus, ['draft', 'scheduled', 'published'], true), function ($query) use ($publishStatus) {
                $query->where('publish_status', $publishStatus);
            })
            ->when($status !== '', function ($query) use ($status) {
                $query->where('status', $status);
            })
            ->latest();

        $series = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => [
                'series' => collect($series->items())
                    ->map(function (Series $item) use ($includeEpisodes) {
                        try {
                            return $this->transformSeriesForList($item, $includeEpisodes);
                        } catch (\Throwable $exception) {
                            report($exception);

                            return null;
                        }
                    })
                    ->filter()
                    ->values(),
                'summary' => $this->adminCatalogSummaryService->seriesSummary(),
                'pagination' => [
                    'current_page' => $series->currentPage(),
                    'last_page' => $series->lastPage(),
                    'per_page' => $series->perPage(),
                    'total' => $series->total(),
                ],
            ],
        ]);
    }

    public function options(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'publish_statuses' => [
                    ['value' => 'draft', 'label' => 'پیش نویس'],
                    ['value' => 'scheduled', 'label' => 'زمان بندی شده'],
                    ['value' => 'published', 'label' => 'منتشر شده'],
                ],
                'production_statuses' => [
                    ['value' => 'Returning Series', 'label' => 'Returning Series - در حال پخش'],
                    ['value' => 'Ended', 'label' => 'Ended - پایان یافته'],
                    ['value' => 'Canceled', 'label' => 'Canceled - لغو شده'],
                    ['value' => 'In Production', 'label' => 'In Production - در حال تولید'],
                    ['value' => 'Pilot', 'label' => 'Pilot - پایلوت'],
                ],
                'show_types' => [
                    ['value' => 'Scripted', 'label' => 'Scripted - داستانی'],
                    ['value' => 'Documentary', 'label' => 'Documentary - مستند'],
                    ['value' => 'Reality', 'label' => 'Reality - رئالیتی'],
                    ['value' => 'Talk Show', 'label' => 'Talk Show - تاک شو'],
                    ['value' => 'Miniseries', 'label' => 'Miniseries - مینی سریال'],
                ],
                'catalog_visibility' => [
                    ['value' => 'catalog_search', 'label' => 'کاتالوگ و نتایج جستجو'],
                    ['value' => 'catalog', 'label' => 'فقط کاتالوگ'],
                    ['value' => 'search', 'label' => 'فقط نتایج جستجو'],
                    ['value' => 'hidden', 'label' => 'مخفی'],
                ],
                'genres' => $this->adminReferenceOptionsService->genres(),
                'countries' => $this->adminReferenceOptionsService->countries(),
                'languages' => $this->adminReferenceOptionsService->languages(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateSeriesPayload($request);

        if (($validated['publish_status'] ?? 'draft') === 'scheduled' && blank($validated['scheduled_for'] ?? null)) {
            return response()->json([
                'success' => false,
                'message' => 'Scheduled series require a schedule date.',
                'errors' => [
                    'scheduled_for' => ['A schedule date is required for scheduled series.'],
                ],
            ], 422);
        }

        $localizedGenres = $this->resolveLocalizedGenres($validated);

        $series = DB::transaction(function () use ($validated, $request, $localizedGenres) {
            $slug = $this->resolveUniqueSlug([
                $validated['slug'] ?? null,
                $validated['original_title'] ?? null,
                $validated['title'] ?? null,
                isset($validated['tmdb_id']) ? 'series-' . $validated['tmdb_id'] : null,
            ]);
            $publishStatus = (string) ($validated['publish_status'] ?? 'draft');
            $publishState = $this->publishScheduleService->resolvePublishState(
                $publishStatus,
                $validated['scheduled_for'] ?? null,
            );
            $seasonPayloads = collect($validated['seasons'] ?? []);
            $episodeTotal = $seasonPayloads
                ->sum(fn (array $season) => count($season['episodes'] ?? []));
            $audioFlags = $this->extractAudioFlags($validated['seasons'] ?? []);

            $series = Series::create([
                ...$this->baseSeriesAttributes($validated, $slug, $localizedGenres),
                'tmdb_id' => isset($validated['tmdb_id']) ? (int) $validated['tmdb_id'] : null,
                'number_of_seasons' => $validated['number_of_seasons'] ?? ($seasonPayloads->count() ?: null),
                'number_of_episodes' => $validated['number_of_episodes'] ?? ($episodeTotal ?: null),
                'has_subtitle' => $audioFlags['has_subtitle'],
                'has_dubbed' => $audioFlags['has_dubbed'],
                'publish_status' => $publishState['publish_status'],
                'scheduled_for' => $publishState['scheduled_for'],
                'published_at' => $publishState['published_at'],
                'metadata' => $this->buildSeriesMetadata($validated, $localizedGenres),
                'created_by' => $request->user()?->id,
            ]);

            $this->syncSeasons(
                $series,
                $validated['seasons'] ?? [],
                $this->nullableString($validated['hls_base_url'] ?? null),
            );

            $this->syncSeriesCatalogFields($series, $validated, $localizedGenres);

            return $series;
        });

        $this->invalidateCatalogCache();
        $this->queueSeriesCreditsSync($series->id, $validated);

        $this->publishScheduleService->syncScheduledPublishRunner($series);

        return response()->json([
            'success' => true,
            'message' => 'Series registered successfully.',
            'data' => $this->slimSeriesResponseData($series),
        ], 201);
    }

    public function show(Series $series): JsonResponse
    {
        $series->load([
            'creator',
            'seasons.episodes',
            'castMembers.person',
            'crewMembers.person',
        ])->loadCount(['seasons', 'episodes']);

        return response()->json([
            'success' => true,
            'data' => [
                'series' => $this->transformSeries($series, true),
            ],
        ]);
    }

    public function update(Request $request, Series $series): JsonResponse
    {
        $validated = $this->validateSeriesPayload($request, $series);

        if (($validated['publish_status'] ?? $series->publish_status) === 'scheduled' && blank($validated['scheduled_for'] ?? null)) {
            return response()->json([
                'success' => false,
                'message' => 'Scheduled series require a schedule date.',
                'errors' => [
                    'scheduled_for' => ['A schedule date is required for scheduled series.'],
                ],
            ], 422);
        }

        $localizedGenres = $this->resolveLocalizedGenres($validated);

        $updated = DB::transaction(function () use ($series, $validated, $localizedGenres) {
            $slug = $this->resolveUniqueSlug([
                $validated['slug'] ?? null,
                $validated['original_title'] ?? null,
                $validated['title'] ?? null,
                isset($validated['tmdb_id']) ? 'series-' . $validated['tmdb_id'] : null,
            ], $series->id);
            $publishStatus = (string) ($validated['publish_status'] ?? $series->publish_status);
            $scheduledInput = $publishStatus === 'scheduled'
                ? ($validated['scheduled_for'] ?? $series->scheduled_for)
                : null;
            $publishState = $this->publishScheduleService->resolvePublishState(
                $publishStatus,
                $scheduledInput,
                $series->published_at,
            );
            $seasonPayloads = collect($validated['seasons'] ?? []);
            $episodeTotal = $seasonPayloads
                ->sum(fn (array $season) => count($season['episodes'] ?? []));
            $audioFlags = $this->extractAudioFlags($validated['seasons'] ?? []);

            $series->update([
                ...$this->baseSeriesAttributes($validated, $slug, $localizedGenres),
                'tmdb_id' => isset($validated['tmdb_id']) ? (int) $validated['tmdb_id'] : null,
                'number_of_seasons' => $validated['number_of_seasons'] ?? ($seasonPayloads->count() ?: null),
                'number_of_episodes' => $validated['number_of_episodes'] ?? ($episodeTotal ?: null),
                'has_subtitle' => $audioFlags['has_subtitle'],
                'has_dubbed' => $audioFlags['has_dubbed'],
                'publish_status' => $publishState['publish_status'],
                'scheduled_for' => $publishState['scheduled_for'],
                'published_at' => $publishState['published_at'],
                'metadata' => $this->buildSeriesMetadata($validated, $localizedGenres),
            ]);

            $this->syncSeasons(
                $series,
                $validated['seasons'] ?? [],
                $this->nullableString($validated['hls_base_url'] ?? null),
            );

            $this->syncSeriesCatalogFields($series, $validated, $localizedGenres);

            return $series;
        });

        $this->invalidateCatalogCache();
        $this->queueSeriesCreditsSync($updated->id, $validated);

        $this->publishScheduleService->syncScheduledPublishRunner($updated);

        return response()->json([
            'success' => true,
            'message' => 'Series saved successfully.',
            'data' => $this->slimSeriesResponseData($updated),
        ]);
    }

    public function updatePublishStatus(Request $request, Series $series): JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'publish_status' => ['required', Rule::in(['draft', 'scheduled', 'published'])],
            'scheduled_for' => ['nullable', 'string', 'max:32'],
        ])->validate();

        $publishStatus = $validated['publish_status'];
        $scheduledInput = $publishStatus === 'scheduled'
            ? ($validated['scheduled_for'] ?? $series->scheduled_for)
            : null;

        if ($publishStatus === 'scheduled' && blank($scheduledInput)) {
            return response()->json([
                'success' => false,
                'message' => 'Scheduled series require a schedule date.',
                'errors' => [
                    'scheduled_for' => ['A schedule date is required for scheduled series.'],
                ],
            ], 422);
        }

        $publishState = $this->publishScheduleService->resolvePublishState(
            $publishStatus,
            $scheduledInput,
            $series->published_at,
        );

        $series->update([
            'publish_status' => $publishState['publish_status'],
            'scheduled_for' => $publishState['scheduled_for'],
            'published_at' => $publishState['published_at'],
        ]);

        $this->invalidateCatalogCache();

        $this->publishScheduleService->syncScheduledPublishRunner($series);

        $series->refresh();
        $series->load(['creator', 'seasons.episodes']);

        return response()->json([
            'success' => true,
            'message' => match ($publishState['publish_status']) {
                'published' => 'Series published successfully.',
                'scheduled' => 'Series scheduled successfully.',
                default => 'Series moved back to draft.',
            },
            'data' => [
                'series' => $this->transformSeries($series, true),
            ],
        ]);
    }

    public function destroy(Series $series): JsonResponse
    {
        $title = $series->title;
        $slug = $series->slug;

        DB::transaction(function () use ($series): void {
            $series->episodes()->delete();
            $series->seasons()->delete();
            $series->castMembers()->delete();
            $series->crewMembers()->delete();
            $series->genres()->detach();
            $series->delete();
        });

        $this->invalidateCatalogCache();

        return response()->json([
            'success' => true,
            'message' => 'Series deleted successfully.',
            'data' => [
                'deleted_series' => [
                    'title' => $title,
                    'slug' => $slug,
                ],
            ],
        ]);
    }

    private function validateSeriesPayload(Request $request, ?Series $series = null): array
    {
        $slugRule = Rule::unique('series', 'slug');

        if ($series) {
            $slugRule->ignore($series->id);
        }

        return Validator::make($request->all(), [
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', $slugRule],
            'original_title' => ['nullable', 'string', 'max:255'],
            'synopsis' => ['nullable', 'string'],
            'first_air_date' => ['nullable', 'date'],
            'last_air_date' => ['nullable', 'date'],
            'release_year' => ['nullable', 'integer', 'between:1888,2100'],
            'status' => ['nullable', 'string', 'max:120'],
            'show_type' => ['nullable', 'string', 'max:120'],
            'episode_runtime_minutes' => ['nullable', 'integer', 'min:1', 'max:999'],
            'number_of_seasons' => ['nullable', 'integer', 'min:0', 'max:999'],
            'number_of_episodes' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'network' => ['nullable', 'string', 'max:255'],
            'provider_name' => ['nullable', 'string', 'max:255'],
            'poster_url' => ['nullable', 'url', 'max:2048'],
            'backdrop_url' => ['nullable', 'url', 'max:2048'],
            'trailer_url' => ['nullable', 'url', 'max:2048'],
            'homepage_url' => ['nullable', 'url', 'max:2048'],
            'age_rating' => ['nullable', 'string', 'max:50'],
            'catalog_visibility' => ['nullable', Rule::in(['catalog_search', 'catalog', 'search', 'hidden'])],
            'is_featured' => ['nullable', 'boolean'],
            'publish_status' => ['nullable', Rule::in(['draft', 'scheduled', 'published'])],
            'scheduled_for' => ['nullable', 'string', 'max:32'],
            'tmdb_query' => ['nullable', 'string', 'max:255'],
            'tmdb_id' => ['nullable', 'integer', 'min:1'],
            'imdb_id' => ['nullable', 'string', 'max:32'],
            'tvdb_id' => ['nullable', 'string', 'max:32'],
            'rating' => ['nullable', 'numeric', 'between:0,10'],
            'original_language' => ['nullable', 'string', 'max:12'],
            'creators' => ['nullable', 'array'],
            'creators.*' => ['string', 'max:255'],
            'directors' => ['nullable', 'array'],
            'directors.*' => ['string', 'max:255'],
            'writers' => ['nullable', 'array'],
            'writers.*' => ['string', 'max:255'],
            'genres' => ['nullable', 'array'],
            'genres.*' => ['string', 'max:120'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:120'],
            'languages' => ['nullable', 'array'],
            'languages.*' => ['string', 'max:120'],
            'countries' => ['nullable', 'array'],
            'countries.*' => ['string', 'max:120'],
            'actors' => ['nullable', 'array'],
            'actors.*.name' => ['required_with:actors', 'string', 'max:255'],
            'actors.*.character' => ['nullable', 'string', 'max:255'],
            'actors.*.profile_url' => ['nullable', 'url', 'max:2048'],
            'cast' => ['nullable', 'array'],
            'cast.*.tmdb_person_id' => ['nullable', 'integer', 'min:0'],
            'cast.*.name' => ['nullable', 'string', 'max:255'],
            'cast.*.character' => ['nullable', 'string', 'max:500'],
            'cast.*.profile_url' => ['nullable', 'string', 'max:2048'],
            'cast.*.credit_id' => ['nullable', 'string', 'max:100'],
            'cast.*.order' => ['nullable', 'integer', 'min:0'],
            'cast.*.known_for_department' => ['nullable', 'string', 'max:100'],
            'cast.*.episode_count' => ['nullable', 'integer', 'min:0'],
            'crew' => ['nullable', 'array'],
            'crew.*.tmdb_person_id' => ['nullable', 'integer', 'min:0'],
            'crew.*.name' => ['nullable', 'string', 'max:255'],
            'crew.*.job' => ['nullable', 'string', 'max:255'],
            'crew.*.department' => ['nullable', 'string', 'max:255'],
            'crew.*.profile_url' => ['nullable', 'string', 'max:2048'],
            'crew.*.credit_id' => ['nullable', 'string', 'max:100'],
            'hls_base_url' => ['nullable', 'string', 'max:2048'],
            'seasons' => ['nullable', 'array'],
            'seasons.*.season_number' => ['required_with:seasons', 'integer', 'min:0', 'max:999'],
            'seasons.*.hls_base_url' => ['nullable', 'string', 'max:2048'],
            'seasons.*.name' => ['nullable', 'string', 'max:255'],
            'seasons.*.synopsis' => ['nullable', 'string'],
            'seasons.*.synopsis_en' => ['nullable', 'string'],
            'seasons.*.air_date' => ['nullable', 'date'],
            'seasons.*.episode_count' => ['nullable', 'integer', 'min:0', 'max:999'],
            'seasons.*.poster_url' => ['nullable', 'url', 'max:2048'],
            'seasons.*.episodes' => ['nullable', 'array'],
            'seasons.*.episodes.*.episode_number' => ['required_with:seasons.*.episodes', 'integer', 'min:0', 'max:999'],
            'seasons.*.episodes.*.title' => ['nullable', 'string', 'max:255'],
            'seasons.*.episodes.*.synopsis' => ['nullable', 'string'],
            'seasons.*.episodes.*.synopsis_en' => ['nullable', 'string'],
            'seasons.*.episodes.*.air_date' => ['nullable', 'date'],
            'seasons.*.episodes.*.duration_minutes' => ['nullable', 'integer', 'min:1', 'max:999'],
            'seasons.*.episodes.*.still_url' => ['nullable', 'url', 'max:2048'],
            'seasons.*.episodes.*.source_method' => ['nullable', Rule::in(['upload', 'embed', 'url', 'hls'])],
            'seasons.*.episodes.*.video_url' => ['nullable', 'url', 'max:2048'],
            'seasons.*.episodes.*.embed_url' => ['nullable', 'url', 'max:2048'],
            'seasons.*.episodes.*.hls_url' => ['nullable', 'string', 'max:2048'],
            'seasons.*.episodes.*.hls_verified' => ['nullable', 'boolean'],
            'seasons.*.episodes.*.download_links' => ['nullable', 'array'],
            'seasons.*.episodes.*.download_links.*' => ['nullable', 'string', 'max:2048'],
            'seasons.*.episodes.*.subtitle_url' => ['nullable', 'url', 'max:2048'],
            'seasons.*.episodes.*.dubbed_audio_url' => ['nullable', 'url', 'max:2048'],
            'seasons.*.episodes.*.publish_status' => ['nullable', Rule::in(['draft', 'scheduled', 'published'])],
            'tmdb_excluded_season_numbers' => ['nullable', 'array'],
            'tmdb_excluded_season_numbers.*' => ['integer', 'min:1', 'max:999'],
            'tmdb_excluded_episodes' => ['nullable', 'array'],
            'tmdb_excluded_episodes.*.season_number' => ['required_with:tmdb_excluded_episodes', 'integer', 'min:1', 'max:999'],
            'tmdb_excluded_episodes.*.episode_number' => ['required_with:tmdb_excluded_episodes', 'integer', 'min:1', 'max:999'],
        ])->validate();
    }

    /**
     * @return array<int, string>
     */
    private function resolveLocalizedGenres(array $validated): array
    {
        return $this->tmdbService->localizeGenreNames(
            $this->normalizeStringList($validated['genres'] ?? []),
            'tv',
        );
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function queueSeriesCreditsSync(int $seriesId, array $validated): void
    {
        $cast = collect($validated['cast'] ?? [])
            ->filter(fn ($row) => is_array($row) && (filled($row['name'] ?? null) || filled($row['tmdb_person_id'] ?? null)))
            ->values()
            ->all();

        if ($cast === []) {
            $cast = $this->normalizeActors($validated['actors'] ?? []);
        }

        $crew = collect($validated['crew'] ?? [])
            ->filter(fn ($row) => is_array($row))
            ->values()
            ->all();

        $tmdbId = isset($validated['tmdb_id']) ? (int) $validated['tmdb_id'] : null;

        SyncSeriesPersonCreditsJob::dispatch($seriesId, $cast, $crew, $tmdbId)->afterResponse();
    }

    /**
     * @return array{series: array<string, mixed>}
     */
    private function slimSeriesResponseData(Series $series): array
    {
        return [
            'series' => [
                'id' => $series->id,
                'slug' => $series->slug,
                'title' => $series->title,
                'publish_status' => $series->publish_status,
                'seasons_count' => $series->seasons()->count(),
                'episodes_count' => $series->episodes()->count(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{cast: array<int, array<string, mixed>>, crew: array<int, array<string, mixed>>}|null
     */
    private function creditsPayloadFromValidated(array $validated): ?array
    {
        $cast = collect($validated['cast'] ?? [])
            ->filter(fn ($row) => is_array($row) && (filled($row['name'] ?? null) || filled($row['tmdb_person_id'] ?? null)))
            ->values()
            ->all();

        if ($cast === []) {
            $cast = $this->normalizeActors($validated['actors'] ?? []);
        }

        if ($cast === []) {
            return null;
        }

        return [
            'cast' => $cast,
            'crew' => collect($validated['crew'] ?? [])
                ->filter(fn ($row) => is_array($row))
                ->values()
                ->all(),
        ];
    }

    private function syncSeriesCatalogFields(Series $series, array $validated, ?array $localizedGenres = null): void
    {
        $genres = $localizedGenres ?? $this->resolveLocalizedGenres($validated);
        $countries = $this->normalizeStringList($validated['countries'] ?? []);
        $primaryCountry = $this->normalizeFilterIdentifiers($countries)[0] ?? null;

        $this->genreCatalogService->syncSeriesGenres($series, $genres);
        $series->update([
            'origin_country' => $primaryCountry ? strtoupper(substr($primaryCountry, 0, 2)) : null,
            'original_language' => filled($validated['original_language'] ?? null)
                ? strtolower(trim((string) $validated['original_language']))
                : null,
            'content_rating' => $this->nullableString($validated['age_rating'] ?? null),
        ]);
    }

    private function invalidateCatalogCache(): void
    {
        $this->catalogCacheService->scheduleRebuildAfterContentChange();
    }

    private function baseSeriesAttributes(array $validated, string $slug, ?array $localizedGenres = null): array
    {
        $genres = $localizedGenres ?? $this->resolveLocalizedGenres($validated);
        $countries = $this->normalizeStringList($validated['countries'] ?? []);
        $primaryNetwork = $this->firstFilterValue([
            $this->nullableString($validated['network'] ?? null) ?? '',
            $this->nullableString($validated['provider_name'] ?? null) ?? '',
        ]);
        $filterGenreIds = $this->normalizeFilterIdentifiers($genres);
        $filterCountryIds = $this->normalizeFilterIdentifiers($countries);
        $filterNetworkIds = $primaryNetwork ? $this->normalizeFilterIdentifiers([$primaryNetwork]) : [];

        $attributes = [
            'title' => trim((string) $validated['title']),
            'slug' => $slug,
            'original_title' => $this->nullableString($validated['original_title'] ?? null),
            'synopsis' => $this->nullableString($validated['synopsis'] ?? null),
            'first_air_date' => $validated['first_air_date'] ?? null,
            'last_air_date' => $validated['last_air_date'] ?? null,
            'release_year' => $validated['release_year'] ?? $this->yearFromDate($validated['first_air_date'] ?? null),
            'status' => $this->nullableString($validated['status'] ?? null),
            'show_type' => $this->nullableString($validated['show_type'] ?? null),
            'episode_runtime_minutes' => $validated['episode_runtime_minutes'] ?? null,
            'network' => $this->nullableString($validated['network'] ?? null),
            'provider_name' => $this->nullableString($validated['provider_name'] ?? null),
            'poster_url' => $this->nullableString($validated['poster_url'] ?? null),
            'backdrop_url' => $this->nullableString($validated['backdrop_url'] ?? null),
            'trailer_url' => $this->nullableString($validated['trailer_url'] ?? null),
            'age_rating' => $this->nullableString($validated['age_rating'] ?? null),
            'catalog_visibility' => $validated['catalog_visibility'] ?? 'catalog_search',
            'is_featured' => (bool) ($validated['is_featured'] ?? false),
        ];

        if (Schema::hasColumn('series', 'filter_rating')) {
            $attributes['filter_rating'] = isset($validated['rating']) ? (float) $validated['rating'] : null;
            $attributes['filter_primary_genre'] = $filterGenreIds[0] ?? null;
            $attributes['filter_primary_country'] = $filterCountryIds[0] ?? null;
            $attributes['filter_primary_network'] = $filterNetworkIds[0] ?? null;
        }

        return $attributes;
    }

    private function buildSeriesMetadata(array $validated, ?array $localizedGenres = null): array
    {
        $genres = $localizedGenres ?? $this->resolveLocalizedGenres($validated);
        $countries = $this->normalizeStringList($validated['countries'] ?? []);
        $primaryNetwork = $this->firstFilterValue([
            $this->nullableString($validated['network'] ?? null) ?? '',
            $this->nullableString($validated['provider_name'] ?? null) ?? '',
        ]);
        $filterGenreIds = $this->normalizeFilterIdentifiers($genres);
        $filterCountryIds = $this->normalizeFilterIdentifiers($countries);
        $filterNetworkIds = $primaryNetwork ? $this->normalizeFilterIdentifiers([$primaryNetwork]) : [];

        return [
            'tmdb' => [
                'query' => $this->nullableString($validated['tmdb_query'] ?? null),
                'tmdb_id' => $validated['tmdb_id'] ?? null,
                'imdb_id' => $this->nullableString($validated['imdb_id'] ?? null),
                'tvdb_id' => $this->nullableString($validated['tvdb_id'] ?? null),
                'rating' => isset($validated['rating']) ? (float) $validated['rating'] : null,
                'original_language' => $this->nullableString($validated['original_language'] ?? null),
                'episode_runtime_minutes' => $validated['episode_runtime_minutes'] ?? null,
                'creators' => $this->normalizeStringList($validated['creators'] ?? []),
                'directors' => $this->normalizeStringList($validated['directors'] ?? []),
                'writers' => $this->normalizeStringList($validated['writers'] ?? []),
                'genres' => $genres,
                'tags' => $this->normalizeStringList($validated['tags'] ?? []),
                'languages' => $this->normalizeStringList($validated['languages'] ?? []),
                'countries' => $this->normalizeStringList($validated['countries'] ?? []),
                'actors' => $this->normalizeActors($validated['actors'] ?? []),
                'exclusions' => [
                    'season_numbers' => collect($validated['tmdb_excluded_season_numbers'] ?? [])
                        ->map(fn ($value) => (int) $value)
                        ->filter(fn (int $number) => $number > 0)
                        ->unique()
                        ->values()
                        ->all(),
                    'episodes' => collect($validated['tmdb_excluded_episodes'] ?? [])
                        ->map(function ($row) {
                            if (! is_array($row)) {
                                return null;
                            }

                            $seasonNumber = (int) ($row['season_number'] ?? 0);
                            $episodeNumber = (int) ($row['episode_number'] ?? 0);

                            if ($seasonNumber <= 0 || $episodeNumber <= 0) {
                                return null;
                            }

                            return [
                                'season_number' => $seasonNumber,
                                'episode_number' => $episodeNumber,
                            ];
                        })
                        ->filter()
                        ->unique(fn (array $row) => $row['season_number'] . ':' . $row['episode_number'])
                        ->values()
                        ->all(),
                ],
            ],
            'filters' => [
                'genre_ids' => $filterGenreIds,
                'country_ids' => $filterCountryIds,
                'network_ids' => $filterNetworkIds,
            ],
            'wordpress' => [
                'catalog_visibility' => $validated['catalog_visibility'] ?? 'catalog_search',
                'is_featured' => (bool) ($validated['is_featured'] ?? false),
                'tv_show_style' => 'default',
                'episode_methods' => ['upload', 'embed', 'url', 'hls'],
            ],
            'links' => [
                'homepage_url' => $this->nullableString($validated['homepage_url'] ?? null),
            ],
            'playback' => [
                'hls_base_url' => $this->nullableString($validated['hls_base_url'] ?? null),
            ],
        ];
    }

    private function syncSeasons(Series $series, array $seasons, ?string $seriesHlsBase = null): void
    {
        $seriesHlsBase = $seriesHlsBase
            ?: data_get($series->metadata, 'playback.hls_base_url');

        $payloadSeasonNumbers = collect($seasons)
            ->map(fn (array $season) => (int) ($season['season_number'] ?? 0))
            ->filter(fn (int $number) => $number > 0)
            ->unique()
            ->values()
            ->all();

        if ($payloadSeasonNumbers === []) {
            $series->episodes()->delete();
            $series->seasons()->delete();

            return;
        }

        $series->load(['seasons.episodes']);
        $seasonsByNumber = $series->seasons->keyBy('season_number');

        $series->seasons()
            ->whereNotIn('season_number', $payloadSeasonNumbers)
            ->get()
            ->each(function (SeriesSeason $season) {
                $season->episodes()->delete();
                $season->delete();
            });

        collect($seasons)
            ->sortBy(fn (array $season) => (int) ($season['season_number'] ?? 0))
            ->values()
            ->each(function (array $seasonPayload, int $seasonIndex) use ($series, $seriesHlsBase, &$seasonsByNumber) {
                $episodes = collect($seasonPayload['episodes'] ?? [])
                    ->sortBy(fn (array $episode) => (int) ($episode['episode_number'] ?? 0))
                    ->values();
                $seasonNumber = (int) $seasonPayload['season_number'];
                $seasonHlsBase = $this->nullableString($seasonPayload['hls_base_url'] ?? null)
                    ?: $this->seriesHlsPathService->buildSeasonHlsBase($seriesHlsBase, $seasonNumber);

                $existingSeason = $seasonsByNumber->get($seasonNumber);
                $existingSeasonMetadata = is_array($existingSeason?->metadata)
                    ? $existingSeason->metadata
                    : [];

                $seasonSynopsisFields = $this->synopsisHelper->localizeSeasonOrEpisode(
                    $this->nullableString($seasonPayload['synopsis'] ?? null),
                    $this->nullableString($seasonPayload['synopsis_en'] ?? null),
                    false,
                );
                $seasonSynopsis = $seasonSynopsisFields['synopsis'];
                $seasonSynopsisEn = $seasonSynopsisFields['synopsis_en'];

                $seasonAttributes = [
                    'name' => $this->nullableString($seasonPayload['name'] ?? null) ?: 'Season ' . $seasonNumber,
                    'synopsis' => $seasonSynopsis,
                    'air_date' => $seasonPayload['air_date'] ?? null,
                    'episode_count' => $seasonPayload['episode_count'] ?? ($episodes->count() ?: null),
                    'poster_url' => $this->nullableString($seasonPayload['poster_url'] ?? null),
                    'sort_order' => $seasonIndex,
                    'metadata' => [
                        ...$existingSeasonMetadata,
                        'playback' => [
                            ...(array) data_get($existingSeasonMetadata, 'playback', []),
                            'hls_base_url' => $seasonHlsBase,
                        ],
                        'tmdb' => [
                            ...(array) data_get($existingSeasonMetadata, 'tmdb', []),
                            'synopsis_en' => $seasonSynopsisEn,
                        ],
                        'wordpress' => [
                            ...(array) data_get($existingSeasonMetadata, 'wordpress', []),
                            'panel' => 'seasons_episodes',
                        ],
                    ],
                ];

                $season = $existingSeason;

                if ($season) {
                    $season->update($seasonAttributes);
                } else {
                    $season = $series->seasons()->create([
                        ...$seasonAttributes,
                        'season_number' => $seasonNumber,
                    ]);
                    $seasonsByNumber->put($seasonNumber, $season);
                }

                $this->syncSeasonEpisodes($series, $season, $seasonNumber, $episodes);
            });
    }

    private function syncSeasonEpisodes(
        Series $series,
        SeriesSeason $season,
        int $seasonNumber,
        Collection $episodes,
    ): void {
        $payloadEpisodeNumbers = $episodes
            ->map(fn (array $episode) => (int) ($episode['episode_number'] ?? 0))
            ->filter(fn (int $number) => $number > 0)
            ->unique()
            ->values()
            ->all();

        if (!$season->relationLoaded('episodes')) {
            $season->load('episodes');
        }

        if ($payloadEpisodeNumbers === []) {
            $season->episodes()->delete();

            return;
        }

        $season->episodes()
            ->whereNotIn('episode_number', $payloadEpisodeNumbers)
            ->delete();

        $existingEpisodes = $season->episodes->keyBy('episode_number');
        $upsertRows = [];
        $timestamp = now();

        $episodes->each(function (array $episodePayload, int $episodeIndex) use (
            $series,
            $season,
            $seasonNumber,
            $existingEpisodes,
            &$upsertRows,
            $timestamp,
        ) {
            $episodeNumber = (int) $episodePayload['episode_number'];
            $episodeTitle = $this->nullableString($episodePayload['title'] ?? null)
                ?: 'Episode ' . $episodeNumber;
            $episodeHlsUrl = $this->nullableString($episodePayload['hls_url'] ?? null);
            $episodeHlsVerified = (bool) ($episodePayload['hls_verified'] ?? false);

            if (!$episodeHlsVerified) {
                $episodeHlsUrl = null;
            }

            $episodeDownloads = $this->normalizeDownloadLinksMap($episodePayload['download_links'] ?? []);
            $existingEpisode = $existingEpisodes->get($episodeNumber);

            $existingEpisodeMetadata = is_array($existingEpisode?->metadata)
                ? $existingEpisode->metadata
                : [];

            $episodeSynopsisFields = $this->synopsisHelper->localizeSeasonOrEpisode(
                $this->nullableString($episodePayload['synopsis'] ?? null),
                $this->nullableString($episodePayload['synopsis_en'] ?? null),
                false,
            );
            $episodeSynopsis = $episodeSynopsisFields['synopsis'];
            $episodeSynopsisEn = $episodeSynopsisFields['synopsis_en'];

            $metadata = [
                ...$existingEpisodeMetadata,
                'playback' => [
                    ...(array) data_get($existingEpisodeMetadata, 'playback', []),
                    'method' => $episodePayload['source_method'] ?? 'hls',
                    'hls_verified' => $episodeHlsVerified,
                    'downloads' => $episodeDownloads,
                ],
                'tmdb' => [
                    ...(array) data_get($existingEpisodeMetadata, 'tmdb', []),
                    'synopsis_en' => $episodeSynopsisEn,
                ],
            ];

            $slug = $existingEpisode?->slug
                ?: $this->episodeSlug($series->slug, $seasonNumber, $episodeNumber, $episodeTitle);
            $createdAt = $existingEpisode?->created_at ?? $timestamp;

            $upsertRows[] = [
                'series_id' => $series->id,
                'series_season_id' => $season->id,
                'season_number' => $seasonNumber,
                'episode_number' => $episodeNumber,
                'title' => $episodeTitle,
                'slug' => $slug,
                'synopsis' => $episodeSynopsis,
                'air_date' => $episodePayload['air_date'] ?? null,
                'duration_minutes' => $episodePayload['duration_minutes'] ?? null,
                'still_url' => $this->nullableString($episodePayload['still_url'] ?? null),
                'source_method' => $episodePayload['source_method'] ?? 'hls',
                'video_url' => $this->nullableString($episodePayload['video_url'] ?? null),
                'embed_url' => $this->nullableString($episodePayload['embed_url'] ?? null),
                'hls_url' => $episodeHlsUrl,
                'subtitle_url' => $this->nullableString($episodePayload['subtitle_url'] ?? null),
                'dubbed_audio_url' => $this->nullableString($episodePayload['dubbed_audio_url'] ?? null),
                'publish_status' => $episodePayload['publish_status'] ?? 'draft',
                'sort_order' => $episodeIndex,
                'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE) ?: '{}',
                'created_at' => $createdAt,
                'updated_at' => $timestamp,
            ];
        });

        if ($upsertRows === []) {
            return;
        }

        DB::table('series_episodes')->upsert(
            $upsertRows,
            ['series_id', 'season_number', 'episode_number'],
            [
                'series_season_id',
                'title',
                'slug',
                'synopsis',
                'air_date',
                'duration_minutes',
                'still_url',
                'source_method',
                'video_url',
                'embed_url',
                'hls_url',
                'subtitle_url',
                'dubbed_audio_url',
                'publish_status',
                'sort_order',
                'metadata',
                'updated_at',
            ],
        );
    }

    private function transformSeriesListItem(Series $series): array
    {
        $series->loadMissing(['creator']);

        return [
            'id' => $series->id,
            'title' => $series->title,
            'slug' => $series->slug,
            'synopsis' => $series->synopsis,
            'first_air_date' => $series->first_air_date?->toDateString(),
            'status' => $series->status,
            'network' => $series->network,
            'poster_url' => $series->poster_url,
            'has_subtitle' => (bool) ($series->has_subtitle ?? false),
            'has_dubbed' => (bool) ($series->has_dubbed ?? false),
            'is_featured' => $series->is_featured,
            'publish_status' => $series->publish_status,
            'seasons_count' => (int) ($series->getAttribute('seasons_count') ?? 0),
            'episodes_count' => (int) ($series->getAttribute('episodes_count') ?? 0),
            'created_at' => $series->created_at?->toISOString(),
            'updated_at' => $series->updated_at?->toISOString(),
            'created_by' => $series->creator?->only(['id', 'name', 'email']),
        ];
    }

    private function transformSeriesForList(Series $series, bool $includeEpisodes): array
    {
        $series->loadMissing(['creator', 'seasons']);

        $data = $this->transformSeriesListItem($series);
        $data['seasons'] = $series->seasons
            ->map(fn (SeriesSeason $season) => $this->transformSeasonForList($season, $includeEpisodes))
            ->values()
            ->all();

        return $data;
    }

    private function transformSeasonForList(SeriesSeason $season, bool $includeEpisodes): array
    {
        $payload = [
            'id' => $season->id,
            'season_number' => $season->season_number,
            'name' => $season->name,
            'synopsis' => $season->synopsis,
            'air_date' => $season->air_date?->toDateString(),
            'episode_count' => $season->episode_count,
            'poster_url' => $season->poster_url,
        ];

        if ($includeEpisodes) {
            $season->loadMissing(['episodes']);
            $payload['episodes'] = $season->episodes
                ->map(fn (SeriesEpisode $episode) => $this->transformEpisodeForList($episode))
                ->values()
                ->all();
        }

        return $payload;
    }

    private function transformEpisodeForList(SeriesEpisode $episode): array
    {
        $metadata = is_array($episode->metadata) ? $episode->metadata : [];

        return [
            'id' => $episode->id,
            'season_number' => $episode->season_number,
            'episode_number' => $episode->episode_number,
            'title' => $episode->title,
            'slug' => $episode->slug,
            'air_date' => $episode->air_date?->toDateString(),
            'still_url' => $episode->still_url,
            'source_method' => $episode->source_method,
            'video_url' => $episode->video_url,
            'embed_url' => $episode->embed_url,
            'hls_url' => $episode->hls_url,
            'hls_verified' => (bool) data_get($episode->metadata, 'playback.hls_verified', false),
            'publish_status' => $episode->publish_status,
            'subtitle_url' => $episode->subtitle_url,
            'dubbed_audio_url' => $episode->dubbed_audio_url,
            'download_links' => data_get($metadata, 'playback.downloads', []),
        ];
    }

    private function transformSeries(
        Series $series,
        bool $includeSeasons = false,
        ?array $creditsPayload = null,
    ): array
    {
        $series->loadMissing(['creator']);
        $metadata = is_array($series->metadata) ? $series->metadata : [];

        $hasSubtitle = (bool) ($series->has_subtitle ?? false);
        $hasDubbed = (bool) ($series->has_dubbed ?? false);

        $data = [
            'id' => $series->id,
            'tmdb_id' => $series->tmdb_id,
            'title' => $series->title,
            'slug' => $series->slug,
            'original_title' => $series->original_title,
            'synopsis' => $series->synopsis,
            'first_air_date' => $series->first_air_date?->toDateString(),
            'last_air_date' => $series->last_air_date?->toDateString(),
            'release_year' => $series->release_year,
            'status' => $series->status,
            'show_type' => $series->show_type,
            'episode_runtime_minutes' => $series->episode_runtime_minutes,
            'number_of_seasons' => $series->number_of_seasons,
            'number_of_episodes' => $series->number_of_episodes,
            'seasons_count' => (int) ($series->getAttribute('seasons_count') ?? 0),
            'episodes_count' => (int) ($series->getAttribute('episodes_count') ?? 0),
            'network' => $series->network,
            'provider_name' => $series->provider_name,
            'poster_url' => $series->poster_url,
            'backdrop_url' => $series->backdrop_url,
            'trailer_url' => $series->trailer_url,
            'homepage_url' => data_get($metadata, 'links.homepage_url'),
            'hls_base_url' => data_get($metadata, 'playback.hls_base_url'),
            'age_rating' => $series->age_rating,
            'has_subtitle' => $hasSubtitle,
            'has_dubbed' => $hasDubbed,
            'catalog_visibility' => $series->catalog_visibility,
            'is_featured' => $series->is_featured,
            'publish_status' => $series->publish_status,
            'scheduled_for' => $this->publishScheduleService->formatScheduleForApi($series->scheduled_for),
            'published_at' => $series->published_at?->toISOString(),
            'metadata' => [
                'tmdb' => [
                    'query' => data_get($metadata, 'tmdb.query'),
                    'tmdb_id' => data_get($metadata, 'tmdb.tmdb_id'),
                    'imdb_id' => data_get($metadata, 'tmdb.imdb_id'),
                    'tvdb_id' => data_get($metadata, 'tmdb.tvdb_id'),
                    'rating' => data_get($metadata, 'tmdb.rating'),
                    'original_language' => data_get($metadata, 'tmdb.original_language'),
                    'episode_runtime_minutes' => data_get($metadata, 'tmdb.episode_runtime_minutes'),
                    'creators' => data_get($metadata, 'tmdb.creators', []),
                    'directors' => data_get($metadata, 'tmdb.directors', []),
                    'writers' => data_get($metadata, 'tmdb.writers', []),
                    'genres' => $this->tmdbService->localizeGenreNames(
                        data_get($metadata, 'tmdb.genres', []),
                        'tv',
                    ),
                    'tags' => data_get($metadata, 'tmdb.tags', []),
                    'languages' => data_get($metadata, 'tmdb.languages', []),
                    'countries' => data_get($metadata, 'tmdb.countries', []),
                    'actors' => data_get($metadata, 'tmdb.actors', []),
                    'exclusions' => [
                        'season_numbers' => collect(data_get($metadata, 'tmdb.exclusions.season_numbers', []))
                            ->map(fn ($value) => (int) $value)
                            ->filter(fn (int $number) => $number > 0)
                            ->values()
                            ->all(),
                        'episodes' => collect(data_get($metadata, 'tmdb.exclusions.episodes', []))
                            ->map(function ($row) {
                                if (! is_array($row)) {
                                    return null;
                                }

                                $seasonNumber = (int) ($row['season_number'] ?? 0);
                                $episodeNumber = (int) ($row['episode_number'] ?? 0);

                                if ($seasonNumber <= 0 || $episodeNumber <= 0) {
                                    return null;
                                }

                                return [
                                    'season_number' => $seasonNumber,
                                    'episode_number' => $episodeNumber,
                                ];
                            })
                            ->filter()
                            ->values()
                            ->all(),
                    ],
                ],
                'wordpress' => [
                    'catalog_visibility' => data_get($metadata, 'wordpress.catalog_visibility', $series->catalog_visibility),
                    'is_featured' => (bool) data_get($metadata, 'wordpress.is_featured', $series->is_featured),
                    'tv_show_style' => data_get($metadata, 'wordpress.tv_show_style', 'default'),
                    'episode_methods' => data_get($metadata, 'wordpress.episode_methods', ['upload', 'embed', 'url', 'hls']),
                ],
            ],
            'created_at' => $series->created_at?->toISOString(),
            'updated_at' => $series->updated_at?->toISOString(),
            'created_by' => $series->creator?->only(['id', 'name', 'email']),
        ];

        if ($includeSeasons) {
            $series->loadMissing(['seasons.episodes']);

            $data['seasons'] = $series->seasons
                ->map(fn (SeriesSeason $season) => $this->transformSeason($season))
                ->values()
                ->all();

            if ($creditsPayload !== null) {
                $data['cast'] = $this->transformCastPayload($creditsPayload['cast'] ?? []);
                $data['crew'] = $this->transformCrewPayload($creditsPayload['crew'] ?? []);
            } else {
                $data['cast'] = $series->castMembers
                    ->sortBy('cast_order')
                    ->values()
                    ->map(fn (SeriesCast $row) => [
                        'tmdb_person_id' => $row->person?->tmdb_person_id,
                        'person_id' => $row->person_id,
                        'name' => $row->person?->name,
                        'character' => $row->character_name,
                        'profile_url' => $row->person?->getProfileImageUrl(),
                        'credit_id' => $row->tmdb_credit_id,
                        'order' => $row->cast_order,
                        'known_for_department' => $row->person?->known_for_department ?? 'Acting',
                        'episode_count' => $row->episode_count,
                        'slug' => $row->person?->slug,
                    ])
                    ->values()
                    ->all();
                $data['crew'] = $series->crewMembers
                    ->map(fn (SeriesCrew $row) => [
                        'tmdb_person_id' => $row->person?->tmdb_person_id,
                        'person_id' => $row->person_id,
                        'name' => $row->person?->name,
                        'job' => $row->job,
                        'department' => $row->department,
                        'profile_url' => $row->person?->getProfileImageUrl(),
                        'credit_id' => $row->tmdb_credit_id,
                        'slug' => $row->person?->slug,
                    ])
                    ->values()
                    ->all();
            }
        }

        return $data;
    }

    /**
     * @param  array<int, array<string, mixed>>  $cast
     * @return array<int, array<string, mixed>>
     */
    private function transformCastPayload(array $cast): array
    {
        return collect($cast)
            ->sortBy(fn (array $row) => $row['order'] ?? 999)
            ->values()
            ->map(fn (array $row) => [
                'tmdb_person_id' => isset($row['tmdb_person_id']) ? (int) $row['tmdb_person_id'] : null,
                'person_id' => null,
                'name' => $this->nullableString($row['name'] ?? null),
                'character' => $this->nullableString($row['character'] ?? null),
                'profile_url' => $this->nullableString($row['profile_url'] ?? null),
                'credit_id' => $this->nullableString($row['credit_id'] ?? null),
                'order' => (int) ($row['order'] ?? 0),
                'known_for_department' => $this->nullableString($row['known_for_department'] ?? null) ?? 'Acting',
                'episode_count' => isset($row['episode_count']) ? (int) $row['episode_count'] : null,
                'slug' => null,
            ])
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $crew
     * @return array<int, array<string, mixed>>
     */
    private function transformCrewPayload(array $crew): array
    {
        return collect($crew)
            ->values()
            ->map(fn (array $row) => [
                'tmdb_person_id' => isset($row['tmdb_person_id']) ? (int) $row['tmdb_person_id'] : null,
                'person_id' => null,
                'name' => $this->nullableString($row['name'] ?? null),
                'job' => $this->nullableString($row['job'] ?? null),
                'department' => $this->nullableString($row['department'] ?? null),
                'profile_url' => $this->nullableString($row['profile_url'] ?? null),
                'credit_id' => $this->nullableString($row['credit_id'] ?? null),
                'slug' => null,
            ])
            ->all();
    }

    private function transformSeason(SeriesSeason $season): array
    {
        $metadata = is_array($season->metadata) ? $season->metadata : [];
        $synopsisFields = $this->synopsisHelper->normalizeStored(
            $season->synopsis,
            data_get($metadata, 'tmdb.synopsis_en'),
            data_get($metadata, 'tmdb.synopsis_fa'),
        );
        $synopsis = $synopsisFields['synopsis'];
        $synopsisEn = $synopsisFields['synopsis_en'];

        return [
            'id' => $season->id,
            'season_number' => $season->season_number,
            'name' => $season->name,
            'synopsis' => $synopsis,
            'synopsis_en' => $synopsisEn,
            'air_date' => $season->air_date?->toDateString(),
            'episode_count' => $season->episode_count,
            'poster_url' => $season->poster_url,
            'hls_base_url' => data_get($metadata, 'playback.hls_base_url'),
            'episodes' => $season->episodes
                ->map(fn (SeriesEpisode $episode) => $this->transformEpisode($episode))
                ->values()
                ->all(),
        ];
    }

    private function transformEpisode(SeriesEpisode $episode): array
    {
        $metadata = is_array($episode->metadata) ? $episode->metadata : [];

        $synopsisFields = $this->synopsisHelper->normalizeStored(
            $episode->synopsis,
            data_get($metadata, 'tmdb.synopsis_en'),
            data_get($metadata, 'tmdb.synopsis_fa'),
        );
        $synopsis = $synopsisFields['synopsis'];
        $synopsisEn = $synopsisFields['synopsis_en'];

        return [
            'id' => $episode->id,
            'season_number' => $episode->season_number,
            'episode_number' => $episode->episode_number,
            'title' => $episode->title,
            'slug' => $episode->slug,
            'synopsis' => $synopsis,
            'synopsis_en' => $synopsisEn,
            'air_date' => $episode->air_date?->toDateString(),
            'duration_minutes' => $episode->duration_minutes,
            'still_url' => $episode->still_url,
            'source_method' => $episode->source_method,
            'video_url' => $episode->video_url,
            'embed_url' => $episode->embed_url,
            'hls_url' => $episode->hls_url,
            'hls_verified' => (bool) data_get($episode->metadata, 'playback.hls_verified', false),
            'download_links' => data_get($episode->metadata, 'playback.downloads', []),
            'subtitle_url' => $episode->subtitle_url,
            'dubbed_audio_url' => $episode->dubbed_audio_url,
            'publish_status' => $episode->publish_status,
        ];
    }

    private function resolveUniqueSlug(array $candidates, ?int $ignoreSeriesId = null): string
    {
        $baseSlug = '';

        foreach ($candidates as $candidate) {
            if (!filled($candidate)) {
                continue;
            }

            $baseSlug = Str::slug((string) $candidate);

            if ($baseSlug !== '') {
                break;
            }
        }

        if ($baseSlug === '') {
            $baseSlug = 'series-' . Str::lower(Str::random(8));
        }

        $slug = $baseSlug;
        $counter = 2;

        while (
            Series::query()
                ->when($ignoreSeriesId, fn ($query) => $query->whereKeyNot($ignoreSeriesId))
                ->where('slug', $slug)
                ->exists()
        ) {
            $slug = "{$baseSlug}-{$counter}";
            $counter++;
        }

        return $slug;
    }

    private function episodeSlug(string $seriesSlug, int $seasonNumber, int $episodeNumber, string $title): string
    {
        $titleSlug = Str::slug($title) ?: 'episode';

        return "{$seriesSlug}-s{$seasonNumber}-e{$episodeNumber}-{$titleSlug}";
    }

    /**
     * @return array<string, string>
     */
    private function normalizeDownloadLinksMap(mixed $links): array
    {
        if (!is_array($links)) {
            return [];
        }

        $normalized = [];

        foreach ($links as $quality => $url) {
            $qualityKey = trim((string) $quality);
            $urlValue = trim((string) $url);

            if ($qualityKey === '' || $urlValue === '') {
                continue;
            }

            if (!preg_match('/p$/i', $qualityKey) && preg_match('/^\d+$/', $qualityKey) === 1) {
                $qualityKey .= 'p';
            }

            $normalized[$qualityKey] = $urlValue;
        }

        uksort(
            $normalized,
            fn (string $left, string $right) => (int) preg_replace('/\D/', '', $right)
                <=> (int) preg_replace('/\D/', '', $left),
        );

        return $normalized;
    }

    private function normalizeStringList(array $items): array
    {
        return collect($items)
            ->map(fn ($item) => trim((string) $item))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $values
     */
    private function firstFilterValue(array $values): ?string
    {
        foreach ($values as $value) {
            $normalized = trim((string) $value);

            if ($normalized !== '') {
                return $normalized;
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>  $values
     * @return array<int, string>
     */
    private function normalizeFilterIdentifiers(array $values): array
    {
        return collect($values)
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->map(function (string $value): string {
                $normalized = strtolower($value);
                $normalized = preg_replace('/[^a-z0-9]+/i', '-', $normalized) ?? '';
                $normalized = trim($normalized, '-');

                return $normalized !== '' ? $normalized : strtolower($value);
            })
            ->unique()
            ->values()
            ->all();
    }

    private function normalizeActors(array $actors): array
    {
        return collect($actors)
            ->map(function (array $actor) {
                $name = trim((string) ($actor['name'] ?? ''));

                if ($name === '') {
                    return null;
                }

                $character = trim((string) ($actor['character'] ?? ''));
                $profileUrl = trim((string) ($actor['profile_url'] ?? ''));

                return [
                    'name' => $name,
                    'character' => $character !== '' ? $character : null,
                    'profile_url' => $profileUrl !== '' ? $profileUrl : null,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function nullableString(mixed $value): ?string
    {
        $normalized = trim((string) ($value ?? ''));

        return $normalized !== '' ? $normalized : null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $seasons
     * @return array{has_subtitle: bool, has_dubbed: bool}
     */
    private function extractAudioFlags(array $seasons): array
    {
        $hasSubtitle = false;
        $hasDubbed = false;

        foreach ($seasons as $season) {
            $episodes = is_array($season['episodes'] ?? null) ? $season['episodes'] : [];
            foreach ($episodes as $episode) {
                $subtitleUrl = trim((string) ($episode['subtitle_url'] ?? ''));
                $dubbedAudioUrl = trim((string) ($episode['dubbed_audio_url'] ?? ''));

                if ($subtitleUrl !== '') {
                    $hasSubtitle = true;
                }

                if ($dubbedAudioUrl !== '') {
                    $hasDubbed = true;
                }

                if ($hasSubtitle && $hasDubbed) {
                    return ['has_subtitle' => true, 'has_dubbed' => true];
                }
            }
        }

        return ['has_subtitle' => $hasSubtitle, 'has_dubbed' => $hasDubbed];
    }

    private function yearFromDate(?string $date): ?int
    {
        if (!$date || !preg_match('/^\d{4}/', $date, $matches)) {
            return null;
        }

        return (int) $matches[0];
    }
}

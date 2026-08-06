<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessMovieImagesJob;
use App\Jobs\SyncMovieCollectionJob;
use App\Jobs\SyncMoviePersonCreditsJob;
use App\Jobs\ValidateHlsManifestJob;
use App\Models\Movie;
use App\Models\ContentChangeLog;
use App\Models\MovieCast;
use App\Models\MovieCrew;
use App\Services\Cinema\AdminCatalogSummaryService;
use App\Services\Cinema\AdminReferenceOptionsService;
use App\Services\Cinema\CatalogAdminRankingEnricher;
use App\Services\Cinema\CatalogContentRankingSyncService;
use App\Services\Cinema\CatalogPopularityPersistence;
use App\Services\Cinema\CatalogCacheService;
use App\Services\Cinema\CatalogFilterQuery;
use App\Services\Cinema\CollectionService;
use App\Services\Cinema\CountryCatalogService;
use App\Services\Cinema\CatalogListProcedureService;
use App\Services\Cinema\GenreAlignmentService;
use App\Services\Cinema\GenreCatalogService;
use App\Support\CatalogListResponseMapper;
use App\Support\CinemaSynopsisHelper;
use App\Support\CinemaValidation;
use App\Support\ContentMetadataOptions;
use App\Services\Cinema\ContentImageIngestionService;
use App\Services\Cinema\ContentPipelineLauncher;
use App\Services\Cinema\ContentPipelineStatusService;
use App\Services\Cinema\ImagePresentationService;
use App\Services\Cinema\MediaPathService;
use App\Services\Cinema\MovieCleanupService;
use App\Services\Cinema\MovieDraftUpdateService;
use App\Services\Cinema\MovieIngestionService;
use App\Services\Cinema\HlsPackageInsightService;
use App\Services\Cinema\ContentChangeLogger;
use App\Services\Cinema\ContentPreviewTokenService;
use App\Services\Cinema\PublishIdempotencyService;
use App\Services\Cinema\PublishScheduleService;
use App\Services\TmdbService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class AdminMovieController extends Controller
{
    public function __construct(
        private readonly MovieIngestionService $movieIngestionService,
        private readonly MovieCleanupService $movieCleanupService,
        private readonly MediaPathService $mediaPathService,
        private readonly MovieDraftUpdateService $movieDraftUpdateService,
        private readonly HlsPackageInsightService $hlsPackageInsightService,
        private readonly TmdbService $tmdbService,
        private readonly GenreCatalogService $genreCatalogService,
        private readonly GenreAlignmentService $genreAlignmentService,
        private readonly CountryCatalogService $countryCatalogService,
        private readonly AdminReferenceOptionsService $adminReferenceOptionsService,
        private readonly AdminCatalogSummaryService $adminCatalogSummaryService,
        private readonly CatalogListProcedureService $catalogListProcedureService,
        private readonly CatalogListResponseMapper $catalogListResponseMapper,
        private readonly CatalogCacheService $catalogCacheService,
        private readonly CatalogFilterQuery $catalogFilterQuery,
        private readonly CollectionService $collectionService,
        private readonly CinemaSynopsisHelper $synopsisHelper,
        private readonly ContentImageIngestionService $contentImageIngestionService,
        private readonly ImagePresentationService $imagePresentationService,
        private readonly ContentPipelineLauncher $contentPipelineLauncher,
        private readonly ContentPipelineStatusService $contentPipelineStatusService,
        private readonly CatalogPopularityPersistence $catalogPopularityPersistence,
        private readonly CatalogAdminRankingEnricher $catalogAdminRankingEnricher,
        private readonly CatalogContentRankingSyncService $catalogContentRankingSyncService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->integer('per_page', 12), 1), 50);
        $publishStatus = (string) $request->string('publish_status');
        $visibility = (string) $request->string('catalog_visibility');
        $slider = (string) $request->string('slider');

        $query = Movie::query()->with(['creator']);
        $this->catalogFilterQuery->apply($query, $request, 'movies', false);

        if (in_array($publishStatus, ['draft', 'scheduled', 'published'], true)) {
            $query->where('publish_status', $publishStatus);
        }

        if (in_array($visibility, ['catalog_search', 'catalog', 'search', 'hidden'], true)) {
            $query->where('catalog_visibility', $visibility);
        }

        if ($slider === 'homepage') {
            $query->where('is_featured', true)->where(function ($builder) {
                $builder->whereNull('exclude_from_slider')->orWhere('exclude_from_slider', false);
            });
        } elseif ($slider === 'excluded') {
            $query->where('exclude_from_slider', true);
        } elseif ($slider === 'not_featured') {
            $query->where(function ($builder) {
                $builder->whereNull('is_featured')->orWhere('is_featured', false);
            });
        }

        $movies = $query->paginate($perPage);

        $data = [
            'movies' => collect($movies->items())
                ->map(fn (Movie $movie) => $this->transformMovieListItem($movie))
                ->values()
                ->all(),
            'summary' => $this->adminCatalogSummaryService->movieSummary(),
            'pagination' => [
                'current_page' => $movies->currentPage(),
                'last_page' => $movies->lastPage(),
                'per_page' => $movies->perPage(),
                'total' => $movies->total(),
            ],
        ];

        return response()
            ->json(['success' => true, 'data' => $data])
            ->header('Cache-Control', 'no-store, private');
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
                'processing_statuses' => [
                    ['value' => 'pending', 'label' => 'Pending'],
                    ['value' => 'queued', 'label' => 'Queued'],
                    ['value' => 'processing', 'label' => 'Processing'],
                    ['value' => 'ready', 'label' => 'Ready'],
                    ['value' => 'failed', 'label' => 'Failed'],
                ],
                'hls_profiles' => [],
                'upload_limits' => [
                    'upload_max_filesize' => ini_get('upload_max_filesize') ?: null,
                    'post_max_size' => ini_get('post_max_size') ?: null,
                    'max_execution_time' => ini_get('max_execution_time') ?: null,
                    'max_input_time' => ini_get('max_input_time') ?: null,
                ],
                'catalog_visibility' => [
                    ['value' => 'catalog_search', 'label' => 'کاتالوگ و جستجو'],
                    ['value' => 'catalog', 'label' => 'فقط کاتالوگ'],
                    ['value' => 'search', 'label' => 'فقط جستجو'],
                    ['value' => 'hidden', 'label' => 'مخفی'],
                ],
                'genres' => $this->adminReferenceOptionsService->genres(),
                'countries' => $this->adminReferenceOptionsService->countries(),
                'languages' => $this->adminReferenceOptionsService->languages(),
            ],
        ]);
    }

    public function checkSlug(Request $request): JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'slug' => ['required', 'string', 'max:255'],
            'exclude_id' => ['nullable', 'integer', 'min:1'],
        ])->validate();

        $slug = trim((string) $validated['slug']);
        $excludeId = isset($validated['exclude_id']) ? (int) $validated['exclude_id'] : null;
        $query = Movie::query()->where('slug', $slug);

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        $available = ! $query->exists();

        return response()->json([
            'success' => true,
            'data' => [
                'available' => $available,
                'suggestion' => $available ? null : $this->suggestUniqueMovieSlug($slug, $excludeId),
            ],
        ]);
    }

    private function suggestUniqueMovieSlug(string $baseSlug, ?int $excludeId = null): string
    {
        $candidate = $baseSlug;
        $suffix = 2;

        while ($this->movieSlugTaken($candidate, $excludeId)) {
            $candidate = $baseSlug.'-'.$suffix;
            $suffix++;
        }

        return $candidate;
    }

    private function movieSlugTaken(string $slug, ?int $excludeId = null): bool
    {
        $query = Movie::query()->where('slug', $slug);

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->exists();
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'title' => ['nullable', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', Rule::unique('movies', 'slug')],
            'original_title' => ['required_without:title', 'string', 'max:255'],
            'synopsis' => ['nullable', 'string'],
            'release_year' => ['nullable', 'integer', 'between:1888,2100'],
            'provider_name' => ['nullable', 'string', 'max:255'],
            'poster_path' => ['nullable', 'string', 'max:2048'],
            'poster_file' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,gif'],
            'source_path' => ['nullable', 'string', 'max:2048'],
            'source_file' => ['nullable', 'file', 'mimes:mp4,mkv,mov,avi,m4v,ts'],
            'publish_status' => ['nullable', Rule::in(['draft', 'scheduled', 'published'])],
            'scheduled_for' => ['nullable', 'string', 'max:32'],
            'start_processing' => ['nullable', 'boolean'],
            'selected_hls_profiles' => ['nullable', 'array'],
            'selected_hls_profiles.*' => ['string'],
            'has_dubbed_audio' => ['nullable', 'boolean'],
            'dubbed_source_path' => ['nullable', 'string', 'max:2048'],
            'audio_tracks' => ['nullable', 'array'],
            'audio_tracks.*.label' => ['required_with:audio_tracks.*.source_path', 'string', 'max:120'],
            'audio_tracks.*.language' => ['nullable', 'string', 'max:12'],
            'audio_tracks.*.source_path' => ['required_with:audio_tracks.*.label', 'string', 'max:2048'],
            'has_subtitle' => ['nullable', 'boolean'],
            'subtitle_source_path' => ['nullable', 'string', 'max:2048'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        if (
            $request->string('publish_status')->toString() === 'scheduled'
            && blank($request->input('scheduled_for'))
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Scheduled movies require a schedule date.',
                'errors' => [
                    'scheduled_for' => ['A schedule date is required for scheduled movies.'],
                ],
            ], 422);
        }

        try {
            $movie = $this->movieIngestionService->createMovie(
                $validator->validated(),
                $request->user()
            );
        } catch (InvalidArgumentException $exception) {
            $errorField = $request->hasFile('source_file') ? 'source_file' : 'source_path';

            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'errors' => [
                    $errorField => [$exception->getMessage()],
                ],
            ], 422);
        }

        $this->invalidateAdminMoviesListCache();

        return response()->json([
            'success' => true,
            'message' => 'Movie registered successfully.',
            'data' => [
                'movie' => $this->transformMovie($movie->fresh(['mediaAsset.variants', 'mediaAsset.processingRuns', 'creator'])),
            ],
        ], 201);
    }

    public function show(Movie $movie): JsonResponse
    {
        $movie->load([
            'mediaAsset.variants',
            'mediaAsset.processingRuns.creator',
            'creator',
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'movie' => $this->transformMovie($movie, true),
            ],
        ]);
    }

    public function queueProcessing(Request $request, Movie $movie): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Internal HLS processing has been removed from this project.',
        ], 410);
    }

    public function updateDraftForm(Request $request, Movie $movie): JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'title' => ['required', 'string', 'max:255'],
            'original_title' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', Rule::unique('movies', 'slug')->ignore($movie->id)],
            'release_year' => ['nullable', 'integer', 'between:1888,2100'],
            'provider_name' => ['nullable', 'string', 'max:255'],
            'source_path' => ['nullable', 'string', 'max:2048'],
            'selected_hls_profiles' => ['nullable', 'array'],
            'selected_hls_profiles.*' => ['string'],
            'poster_path' => ['nullable', 'string', 'max:2048'],
            'trailer_path' => ['nullable', 'string', 'max:2048'],
            'has_dubbed_audio' => ['nullable', 'boolean'],
            'dubbed_source_path' => ['nullable', 'string', 'max:2048'],
            'audio_tracks' => ['nullable', 'array'],
            'audio_tracks.*.label' => ['required_with:audio_tracks.*.source_path', 'string', 'max:120'],
            'audio_tracks.*.language' => ['nullable', 'string', 'max:12'],
            'audio_tracks.*.source_path' => ['required_with:audio_tracks.*.label', 'string', 'max:2048'],
            'has_subtitle' => ['nullable', 'boolean'],
            'subtitle_source_path' => ['nullable', 'string', 'max:2048'],
            'synopsis' => ['nullable', 'string'],
            'age_rating' => ['nullable', 'string', 'max:50'],
        ])->validate();

        try {
            $updatedMovie = $this->movieDraftUpdateService->updateMovie($movie, $validated);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'errors' => [
                    'draft' => [$exception->getMessage()],
                ],
            ], 422);
        }

        $this->invalidateAdminMoviesListCache();

        return response()->json([
            'success' => true,
            'message' => 'Draft form saved successfully.',
            'data' => [
                'movie' => $this->transformMovie($updatedMovie, true),
            ],
        ]);
    }

    public function updatePublicationForm(Request $request, Movie $movie): JsonResponse
    {
        $previousSlug = $movie->slug;
        $payload = $request->all();

        if (isset($payload['download_links']) && is_array($payload['download_links'])) {
            $payload['download_links'] = collect($payload['download_links'])
                ->mapWithKeys(function ($url, $key) {
                    $normalizedKey = trim((string) $key);

                    return [$normalizedKey => is_string($url) ? trim($url) : $url];
                })
                ->filter(fn ($url, $key) => $key !== '' && filled($url))
                ->all();
        }

        $validated = Validator::make($payload, [
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', Rule::unique('movies', 'slug')->ignore($movie->id)],
            'original_title' => ['nullable', 'string', 'max:255'],
            'poster_path' => ['nullable', 'string', 'max:2048'],
            'poster_url' => CinemaValidation::nullableImageOrUrl(),
            'trailer_path' => ['nullable', 'string', 'max:2048'],
            'trailer_url' => ['nullable', 'url', 'max:2048'],
            'backdrop_url' => CinemaValidation::nullableImageOrUrl(),
            'banner_url' => CinemaValidation::nullableImageOrUrl(),
            'logo_url' => CinemaValidation::nullableImageOrUrl(),
            'synopsis' => ['nullable', 'string'],
            'age_rating' => ['nullable', 'string', 'max:50'],
            'release_year' => ['nullable', 'integer', 'between:1888,2100'],
            'tmdb_id' => ['nullable', 'integer', 'min:1'],
            'imdb_id' => ['nullable', 'string', 'max:32'],
            'tmdb_query' => ['nullable', 'string', 'max:255'],
            'rating' => ['nullable', 'numeric', 'between:0,10'],
            'popularity' => ['nullable', 'numeric', 'min:0'],
            'vote_count' => ['nullable', 'integer', 'min:0'],
            'original_language' => ['nullable', 'string', 'max:12'],
            'runtime_minutes' => ['nullable', 'integer', 'min:1', 'max:999'],
            'directors' => ['nullable', 'array'],
            'directors.*' => ['string', 'max:255'],
            'writers' => ['nullable', 'array'],
            'writers.*' => ['string', 'max:255'],
            'production_status' => ['nullable', 'string', 'max:120'],
            'provider_name' => ['nullable', 'string', 'max:255'],
            'budget' => ['nullable', 'integer', 'min:0'],
            'revenue' => ['nullable', 'integer', 'min:0'],
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
            'actors.*.profile_url' => CinemaValidation::nullableImageOrUrl(),
            'hls_url' => ['nullable', 'string', 'max:2048'],
            'download_links' => ['nullable', 'array'],
            'download_links.*' => ['nullable', 'string', 'max:2048'],
            'subtitle_source_path' => ['nullable', 'url', 'max:2048'],
            'cast_members' => ['nullable', 'array'],
            'cast_members.*' => ['string', 'max:255'],
            'cast' => ['nullable', 'array'],
            'cast.*.tmdb_person_id' => ['nullable', 'integer', 'min:0'],
            'cast.*.name' => ['nullable', 'string', 'max:255'],
            'cast.*.character' => ['nullable', 'string', 'max:500'],
            'cast.*.profile_url' => ['nullable', 'string', 'max:2048'],
            'cast.*.credit_id' => ['nullable', 'string', 'max:100'],
            'cast.*.order' => ['nullable', 'integer', 'min:0'],
            'cast.*.known_for_department' => ['nullable', 'string', 'max:100'],
            'crew' => ['nullable', 'array'],
            'crew.*.tmdb_person_id' => ['nullable', 'integer', 'min:0'],
            'crew.*.name' => ['nullable', 'string', 'max:255'],
            'crew.*.job' => ['nullable', 'string', 'max:255'],
            'crew.*.department' => ['nullable', 'string', 'max:255'],
            'crew.*.profile_url' => ['nullable', 'string', 'max:2048'],
            'crew.*.credit_id' => ['nullable', 'string', 'max:100'],
            'publish_status' => ['nullable', Rule::in(['draft', 'scheduled', 'published'])],
            'scheduled_for' => ['nullable', 'string', 'max:32'],
            'belongs_to_collection' => ['nullable', 'array'],
            'belongs_to_collection.tmdb_collection_id' => ['nullable', 'integer', 'min:1'],
            'belongs_to_collection.id' => ['nullable', 'integer', 'min:1'],
            'belongs_to_collection.name' => ['nullable', 'string', 'max:255'],
            'belongs_to_collection.poster_url' => CinemaValidation::nullableImageOrUrl(),
            'belongs_to_collection.backdrop_url' => CinemaValidation::nullableImageOrUrl(),
            'drm_scheme' => ['sometimes', Rule::in(['none', 'widevine', 'playready', 'fairplay'])],
            'drm_license_url' => ['sometimes', 'nullable', 'url', 'max:1024'],
            'is_featured' => ['nullable', 'boolean'],
            'exclude_from_slider' => ['nullable', 'boolean'],
            'catalog_visibility' => ['nullable', Rule::in(['catalog_search', 'catalog', 'search', 'hidden'])],
            'meta_title' => ['nullable', 'string', 'max:160'],
            'meta_description' => ['nullable', 'string', 'max:320'],
            'homepage_url' => ['nullable', 'url', 'max:2048'],
            'dash_url' => ['nullable', 'string', 'max:2048'],
            'content_warnings' => ['nullable', 'array'],
            'content_warnings.*' => [Rule::in(ContentMetadataOptions::CONTENT_WARNINGS)],
            'quality_badges' => ['nullable', 'array'],
            'quality_badges.*' => [Rule::in(ContentMetadataOptions::QUALITY_BADGES)],
            'admin_notes' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        $subtitleSourcePath = filled($validated['subtitle_source_path'] ?? null)
            ? trim((string) $validated['subtitle_source_path'])
            : null;

        $existingMetadata = is_array($movie->metadata) ? $movie->metadata : [];
        $downloadLinks = collect($validated['download_links'] ?? [])
            ->mapWithKeys(fn ($value, $key) => [trim((string) $key) => $value])
            ->filter(fn ($value, $key) => $key !== '' && filled($value))
            ->all();
        $manualHlsUrlRaw = isset($validated['hls_url']) ? trim((string) $validated['hls_url']) : null;
        $canonicalHlsUrl = filled($manualHlsUrlRaw)
            ? $this->hlsPackageInsightService->normalizeHlsPackageMasterUrl($manualHlsUrlRaw)
            : null;
        $storedHlsUrl = data_get($existingMetadata, 'playback.hls_url');
        $hlsUrlUnchanged = filled($canonicalHlsUrl)
            && is_string($storedHlsUrl)
            && trim($storedHlsUrl) === trim((string) $canonicalHlsUrl)
            && $movie->processing_status === 'ready';
        $shouldValidateHlsAsync = filled($canonicalHlsUrl) && ! $hlsUrlUnchanged;
        $nextProcessingStatus = match (true) {
            $hlsUrlUnchanged => 'ready',
            filled($canonicalHlsUrl) => 'queued',
            default => 'pending',
        };
        $resolvedSourcePath = $movie->mediaAsset?->source_path;
        $resolvedSubtitleSourcePath = $subtitleSourcePath;
        $resolvedAudioTracks = $movie->audio_tracks ?? [];
        $resolvedHasDubbedAudio = (bool) $movie->has_dubbed_audio;
        $resolvedDubbedSourcePath = $movie->dubbed_source_path;
        $resolvedHasSubtitle = filled($resolvedSubtitleSourcePath);
        $castMembers = collect($validated['cast_members'] ?? [])
            ->map(fn ($item) => trim((string) $item))
            ->filter()
            ->values()
            ->all();
        $directors = collect($validated['directors'] ?? [])
            ->map(fn ($item) => trim((string) $item))
            ->filter()
            ->values()
            ->all();
        $writers = collect($validated['writers'] ?? [])
            ->map(fn ($item) => trim((string) $item))
            ->filter()
            ->values()
            ->all();
        $rawGenreInput = collect($validated['genres'] ?? [])
            ->map(fn ($item) => trim((string) $item))
            ->filter()
            ->values()
            ->all();
        $alignedGenres = $this->genreAlignmentService->align($rawGenreInput, 'movie');
        $genres = $alignedGenres['labels'];
        $tags = collect($validated['tags'] ?? [])
            ->map(fn ($item) => trim((string) $item))
            ->filter()
            ->values()
            ->all();
        $languages = collect($validated['languages'] ?? [])
            ->map(fn ($item) => trim((string) $item))
            ->filter()
            ->values()
            ->all();
        $countries = collect($validated['countries'] ?? [])
            ->map(fn ($item) => trim((string) $item))
            ->filter()
            ->values()
            ->all();
        $ratingValue = isset($validated['rating']) ? (float) $validated['rating'] : null;
        $primaryNetwork = $this->firstFilterValue([
            (string) ($validated['provider_name'] ?? ''),
        ]);
        $countryIsos = $this->countryCatalogService->resolveIsos($countries);
        $filterIds = $this->buildFilterIdentifiers($alignedGenres, $countryIsos, $primaryNetwork);
        $primaryGenre = $alignedGenres['slugs'][0] ?? null;
        $primaryCountry = $countryIsos[0] ?? null;
        $primaryNetworkId = $filterIds['network_ids'][0] ?? null;
        $actors = collect($validated['actors'] ?? [])
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

        if ($castMembers === [] && $actors !== []) {
            $castMembers = collect($actors)
                ->pluck('name')
                ->filter()
                ->values()
                ->all();
        }

        $synopsisFields = $this->synopsisHelper->localizeContentSynopsis(
            $validated['synopsis'] ?? null,
            data_get($existingMetadata, 'tmdb.synopsis_en'),
            autoTranslate: false,
        );
        $manualDashUrl = filled($validated['dash_url'] ?? null)
            ? trim((string) $validated['dash_url'])
            : null;
        $contentWarnings = ContentMetadataOptions::filterAllowedList(
            $validated['content_warnings'] ?? data_get($existingMetadata, 'content_warnings'),
            ContentMetadataOptions::CONTENT_WARNINGS,
        );
        $qualityBadges = ContentMetadataOptions::filterAllowedList(
            $validated['quality_badges'] ?? data_get($existingMetadata, 'quality_badges'),
            ContentMetadataOptions::QUALITY_BADGES,
        );

        $metadata = array_replace_recursive($existingMetadata, [
            'seo' => [
                'meta_title' => filled($validated['meta_title'] ?? null)
                    ? trim((string) $validated['meta_title'])
                    : data_get($existingMetadata, 'seo.meta_title'),
                'meta_description' => filled($validated['meta_description'] ?? null)
                    ? trim((string) $validated['meta_description'])
                    : data_get($existingMetadata, 'seo.meta_description'),
            ],
            'content_warnings' => $contentWarnings,
            'quality_badges' => $qualityBadges,
            'admin_notes' => filled($validated['admin_notes'] ?? null)
                ? trim((string) $validated['admin_notes'])
                : data_get($existingMetadata, 'admin_notes'),
            'tmdb' => array_replace(
                is_array($existingMetadata['tmdb'] ?? null) ? $existingMetadata['tmdb'] : [],
                [
                    'query' => filled($validated['tmdb_query'] ?? null) ? trim((string) $validated['tmdb_query']) : null,
                    'tmdb_id' => $validated['tmdb_id'] ?? null,
                    'imdb_id' => filled($validated['imdb_id'] ?? null) ? trim((string) $validated['imdb_id']) : null,
                    'homepage_url' => filled($validated['homepage_url'] ?? null)
                        ? trim((string) $validated['homepage_url'])
                        : data_get($existingMetadata, 'tmdb.homepage_url'),
                    'rating' => isset($validated['rating']) ? (float) $validated['rating'] : null,
                    'popularity' => isset($validated['popularity']) ? (float) $validated['popularity'] : null,
                    'vote_count' => isset($validated['vote_count']) ? (int) $validated['vote_count'] : null,
                    'original_language' => filled($validated['original_language'] ?? null)
                        ? trim((string) $validated['original_language'])
                        : null,
                    'runtime_minutes' => isset($validated['runtime_minutes']) ? (int) $validated['runtime_minutes'] : null,
                    'directors' => $directors,
                    'writers' => $writers,
                    'production_status' => filled($validated['production_status'] ?? null)
                        ? trim((string) $validated['production_status'])
                        : null,
                    'network' => filled($validated['provider_name'] ?? null)
                        ? trim((string) $validated['provider_name'])
                        : data_get($existingMetadata, 'tmdb.network'),
                    'budget' => isset($validated['budget']) ? (int) $validated['budget'] : null,
                    'revenue' => isset($validated['revenue']) ? (int) $validated['revenue'] : null,
                    'genres' => $genres,
                    'genre_refs' => $alignedGenres['refs'],
                    'tags' => $tags,
                    'languages' => $languages,
                    'countries' => $countries,
                    'country_isos' => $countryIsos,
                    'actors' => $actors,
                    'synopsis_en' => $synopsisFields['synopsis_en'],
                    'belongs_to_collection' => $this->normalizeBelongsToCollectionPayload(
                        $validated['belongs_to_collection'] ?? data_get($existingMetadata, 'tmdb.belongs_to_collection'),
                    ),
                ],
                $this->optionalPublicationMediaMetadata($validated),
            ),
            'filters' => [
                'genre_ids' => $filterIds['genre_ids'],
                'country_ids' => $filterIds['country_ids'],
                'network_ids' => $filterIds['network_ids'],
            ],
            'playback' => [
                'hls_url' => $canonicalHlsUrl ?? $manualHlsUrlRaw,
                'dash_url' => $manualDashUrl ?? data_get($existingMetadata, 'playback.dash_url'),
                'dash_verified' => filled($manualDashUrl)
                    ? true
                    : (bool) data_get($existingMetadata, 'playback.dash_verified', false),
                'downloads' => $downloadLinks,
            ],
        ]);

        $imageResult = $this->contentImageIngestionService->processMoviePublication(
            $movie,
            $validated,
            $metadata,
            deferSecondaryImages: true,
        );
        $metadata = $imageResult['metadata'];

        [$popularityValue, $voteCountValue] = $this->catalogPopularityPersistence->extractFromValidated($validated);
        $popularityApplied = $this->catalogPopularityPersistence->applyToMetadata(
            $metadata,
            $popularityValue,
            $voteCountValue,
            'movies',
        );
        $metadata = $popularityApplied['metadata'];

        if (isset($validated['publish_status']) && $validated['publish_status'] !== 'draft') {
            $metadata['publish'] = array_replace(
                is_array($metadata['publish'] ?? null) ? $metadata['publish'] : [],
                [
                    'pending_intent' => [
                        'status' => $validated['publish_status'],
                        'scheduled_for' => $validated['scheduled_for'] ?? null,
                        'requested_at' => now()->toIso8601String(),
                    ],
                ],
            );
        }

        $movieUpdate = [
            'title' => $validated['title'],
            'slug' => $validated['slug'],
            'original_title' => $validated['original_title'] ?? null,
            'provider_name' => filled($validated['provider_name'] ?? null)
                ? trim((string) $validated['provider_name'])
                : null,
            'tmdb_id' => isset($validated['tmdb_id']) ? (int) $validated['tmdb_id'] : null,
            'poster_path' => $imageResult['poster_path'] ?? $movie->poster_path,
            'trailer_path' => filled($validated['trailer_path'] ?? null)
                ? trim((string) $validated['trailer_path'])
                : $movie->trailer_path,
            'synopsis' => $synopsisFields['synopsis'],
            'age_rating' => $validated['age_rating'] ?? null,
            'release_year' => $validated['release_year'] ?? null,
            'cast_members' => $castMembers,
            'has_dubbed_audio' => $resolvedHasDubbedAudio,
            'dubbed_source_path' => $resolvedDubbedSourcePath,
            'audio_tracks' => $resolvedAudioTracks,
            'has_subtitle' => $resolvedHasSubtitle,
            'subtitle_source_path' => $resolvedSubtitleSourcePath,
            'processing_status' => $nextProcessingStatus,
            'processing_error' => null,
            'metadata' => $metadata,
        ];

        if (Schema::hasColumn('movies', 'drm_scheme')) {
            $movieUpdate['drm_scheme'] = $validated['drm_scheme'] ?? ($movie->drm_scheme ?? 'none');
            $movieUpdate['drm_license_url'] = ($movieUpdate['drm_scheme'] ?? 'none') === 'none'
                ? null
                : ($validated['drm_license_url'] ?? $movie->drm_license_url);
        }

        // If migration hasn't been applied yet, avoid crashing on missing columns.
        if (Schema::hasColumn('movies', 'filter_rating')) {
            $movieUpdate += [
                'filter_rating' => $ratingValue,
                'filter_primary_genre' => $primaryGenre,
                'filter_primary_country' => $primaryCountry,
                'filter_primary_network' => $primaryNetworkId,
                ...$popularityApplied['columns'],
            ];
        }

        if (Schema::hasColumn('movies', 'is_featured')) {
            $isFeatured = (bool) ($validated['is_featured'] ?? false);
            $movieUpdate['is_featured'] = $isFeatured;

            if (Schema::hasColumn('movies', 'exclude_from_slider')) {
                $movieUpdate['exclude_from_slider'] = array_key_exists('exclude_from_slider', $validated)
                    ? (bool) $validated['exclude_from_slider']
                    : ! $isFeatured;
            }
        } elseif (Schema::hasColumn('movies', 'exclude_from_slider')) {
            $movieUpdate['exclude_from_slider'] = (bool) ($validated['exclude_from_slider'] ?? false);
        }

        if (Schema::hasColumn('movies', 'catalog_visibility')) {
            $movieUpdate['catalog_visibility'] = $validated['catalog_visibility'] ?? 'catalog_search';
        }

        $movie->update($movieUpdate);

        $this->genreCatalogService->syncMovieGenres($movie, $genres);
        $this->genreAlignmentService->touchAfterAlignment();

        $collectionData = data_get($metadata, 'tmdb.belongs_to_collection');
        if (is_array($collectionData) && $collectionData !== []) {
            $this->collectionService->linkMovieQuick($collectionData, $movie->id);
            $this->catalogCacheService->forgetPublicCollectionsList();
        }

        SyncMovieCollectionJob::dispatch(
            $movie->id,
            $metadata,
            isset($validated['tmdb_id']) ? (int) $validated['tmdb_id'] : null,
        );

        $movie->update([
            'origin_country' => $primaryCountry,
            'original_language' => filled($validated['original_language'] ?? null)
                ? strtolower(trim((string) $validated['original_language']))
                : (is_string(data_get($metadata, 'tmdb.original_language'))
                    ? strtolower((string) data_get($metadata, 'tmdb.original_language'))
                    : null),
            'content_rating' => filled($validated['age_rating'] ?? null)
                ? trim((string) $validated['age_rating'])
                : null,
        ]);

        if ($movie->mediaAsset) {
            $movie->mediaAsset->update([
                'source_path' => $resolvedSourcePath ?: $movie->mediaAsset->source_path,
                'source_is_external' => $this->isPublicationSourceExternal($resolvedSourcePath ?: $movie->mediaAsset->source_path),
                'master_playlist_relative_path' => (string) (($canonicalHlsUrl ?? $manualHlsUrlRaw) ?: ''),
                'processing_status' => $nextProcessingStatus,
                'processing_progress_percent' => $nextProcessingStatus === 'ready' ? 100 : 0,
                'processing_eta_seconds' => null,
                'current_profile_key' => null,
                'processing_error' => null,
                'last_processed_at' => $nextProcessingStatus === 'ready' ? now() : null,
                'selected_hls_profiles' => [],
            ]);
        } elseif ($resolvedSourcePath) {
            $movie->mediaAsset()->create([
                'kind' => 'video',
                'source_path' => $resolvedSourcePath,
                'source_is_external' => $this->isPublicationSourceExternal($resolvedSourcePath),
                'managed_relative_directory' => $movie->media_folder,
                'hls_output_relative_directory' => $this->mediaPathService->hlsRelativeDirectory($movie->slug),
                'master_playlist_relative_path' => (string) (($canonicalHlsUrl ?? $manualHlsUrlRaw) ?: ''),
                'selected_hls_profiles' => [],
                'processing_status' => $nextProcessingStatus,
                'processing_progress_percent' => 0,
                'processing_eta_seconds' => null,
                'current_profile_key' => null,
                'processing_error' => null,
                'last_processed_at' => null,
            ]);
        }

        $movie->refresh();

        $pipelineStatus = $this->contentPipelineLauncher->launchForMovie(
            $movie,
            $validated,
            $imageResult,
            (int) ($request->user()?->id ?? 0),
            $shouldValidateHlsAsync,
            $hlsUrlUnchanged,
            $canonicalHlsUrl,
            (string) $manualHlsUrlRaw,
            $subtitleSourcePath,
        );

        $movie = $movie->fresh(['mediaAsset.variants', 'mediaAsset.processingRuns', 'creator']);

        $this->catalogContentRankingSyncService->syncAfterAdminSave($movie);

        if ($movie->publish_status === 'published') {
            $this->catalogContentRankingSyncService->scheduleDeferredRankingRefresh($movie, includeTrendingRefresh: true);
        }

        $this->invalidateAdminMoviesListCache(
            filled($movie->slug) ? '/movies/'.$movie->slug : null,
            filled($previousSlug) && $previousSlug !== $movie->slug ? '/movies/'.$previousSlug : null,
        );

        $this->adminCatalogSummaryService->invalidateMovies();

        app(ContentChangeLogger::class)->log(
            'movie',
            $movie->id,
            'updated',
            $request,
            array_keys($validated),
        );

        $resolvedPipelineStatus = (string) ($movie->pipeline_status ?? $pipelineStatus);

        return response()->json([
            'success' => true,
            'message' => 'فرم ثبت شد. پردازش تصاویر، HLS و اعتبار بازیگران در صف انجام می‌شود.',
            'pipeline_status' => $resolvedPipelineStatus,
            'data' => [
                'movie' => $this->transformMovie($movie, true),
            ],
        ]);
    }

    public function getPipeline(Movie $movie): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->contentPipelineStatusService->buildResponse(
                'movie',
                $movie->id,
                (string) ($movie->pipeline_status ?? 'idle'),
                (string) $movie->publish_status,
            ),
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function queueMovieCreditsSync(int $movieId, array $validated): void
    {
        $cast = collect($validated['cast'] ?? [])
            ->filter(fn ($row) => is_array($row) && (filled($row['name'] ?? null) || filled($row['tmdb_person_id'] ?? null)))
            ->values()
            ->all();

        $crew = collect($validated['crew'] ?? [])
            ->filter(fn ($row) => is_array($row))
            ->values()
            ->all();

        $tmdbId = isset($validated['tmdb_id']) ? (int) $validated['tmdb_id'] : null;

        if ($cast === [] && ($tmdbId === null || $tmdbId <= 0)) {
            return;
        }

        if (app()->environment('local', 'testing')) {
            SyncMoviePersonCreditsJob::dispatchSync($movieId, $cast, $crew, $tmdbId);

            return;
        }

        SyncMoviePersonCreditsJob::dispatch($movieId, $cast, $crew, $tmdbId)
            ->onQueue('high');
    }

    public function checkHlsExists(Request $request): JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'base_url' => ['required', 'string', 'max:2048'],
            'episodes' => ['required', 'array', 'max:200'],
            'episodes.*.season' => ['required', 'integer', 'min:1'],
            'episodes.*.episode' => ['required', 'integer', 'min:1'],
        ])->validate();

        $baseUrl = $this->hlsPackageInsightService->normalizeDirectoryUrl(
            trim($validated['base_url']),
        );

        if (!filled($baseUrl)) {
            return response()->json([
                'success' => true,
                'data' => collect($validated['episodes'])->map(fn ($ep) => [
                    'season' => $ep['season'],
                    'episode' => $ep['episode'],
                    'exists' => false,
                ])->all(),
            ]);
        }

        $existingEpisodes = $this->hlsPackageInsightService->checkEpisodesExistParallel(
            $baseUrl,
            $validated['episodes'],
        );

        $results = [];

        foreach ($validated['episodes'] as $ep) {
            $key = sprintf('s%02de%02d', $ep['season'], $ep['episode']);
            $match = $existingEpisodes[$key] ?? ['exists' => false, 'hls_url' => null];

            $results[] = [
                'season' => $ep['season'],
                'episode' => $ep['episode'],
                'exists' => (bool) ($match['exists'] ?? false),
                'hls_url' => filled($match['hls_url'] ?? null) ? $match['hls_url'] : null,
            ];
        }

        return response()->json(['success' => true, 'data' => $results]);
    }

    public function previewHlsPackage(Request $request): JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'hls_url' => ['required', 'string', 'max:2048'],
            'resolve_downloads' => ['sometimes', 'boolean'],
        ])->validate();

        $raw = trim((string) $validated['hls_url']);
        $canonical = $this->hlsPackageInsightService->normalizeHlsPackageMasterUrl($raw);

        if (!filled($canonical)) {
            return response()->json([
                'success' => false,
                'message' => 'A valid HLS package URL or path is required.',
                'errors' => [
                    'hls_url' => ['Invalid HLS URL.'],
                ],
            ], 422);
        }

        $manifest = $this->hlsPackageInsightService->loadHlsContent($canonical);

        if (!filled($manifest) || stripos((string) $manifest, '#EXTM3U') === false) {
            return response()->json([
                'success' => false,
                'message' => 'The HLS master playlist could not be loaded.',
                'errors' => [
                    'hls_url' => ['Could not read master.m3u8 from this URL or path.'],
                ],
            ], 422);
        }

        $insights = $this->hlsPackageInsightService->extractPlaybackInsights(
            $canonical,
            $manifest,
            verifyRemoteDownloadTargets: true,
        );

        $downloadLinks = [];

        if ($request->boolean('resolve_downloads')) {
            try {
                $downloadLinks = $this->hlsPackageInsightService->resolveProgressiveDownloadLinksForPreview($canonical);
            } catch (\Throwable) {
                $downloadLinks = [];
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'canonical_hls_url' => $canonical,
                'subtitle_source_url' => $insights['subtitle_source_path'],
                'dubbed_audio_url' => $insights['dubbed_source_path'],
                'download_links' => $downloadLinks,
                'has_subtitle' => $insights['has_subtitle'],
                'has_dubbed_audio' => $insights['has_dubbed_audio'],
                'dash_url' => $this->resolveDashUrl($canonical, $insights),
                'dash_verified' => filled($this->resolveDashUrl($canonical, $insights)),
                'audio_tracks' => collect($insights['audio_tracks'] ?? [])
                    ->map(fn (array $track) => [
                        'label' => $track['label'] ?? '',
                        'language' => $track['language'] ?? '',
                        'source_url' => $track['source_path'] ?? null,
                    ])
                    ->values()
                    ->all(),
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $insights
     */
    private function resolveDashUrl(string $hlsUrl, array $insights): ?string
    {
        if (! empty($insights['dash_url'])) {
            $candidate = (string) $insights['dash_url'];

            return $this->hlsPackageInsightService->verifyDashManifestUrl($candidate)
                ? $candidate
                : null;
        }

        $base = preg_replace('/\/master\.m3u8(\?.*)?$/i', '', $hlsUrl);
        $candidateMpd = rtrim((string) $base, '/').'/manifest.mpd';

        return $this->hlsPackageInsightService->verifyDashManifestUrl($candidateMpd)
            ? $candidateMpd
            : null;
    }

    private function isPublicationSourceExternal(?string $path): bool
    {
        if (!filled($path)) {
            return false;
        }

        if (preg_match('#^https?://#i', (string) $path) === 1) {
            return true;
        }

        return false;
    }

    public function stopProcessing(Movie $movie): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Internal HLS processing has been removed from this project.',
        ], 410);
    }

    public function destroy(Movie $movie): JsonResponse
    {
        $title = $movie->title;
        $slug = $movie->slug;

        $this->movieCleanupService->deleteMovie($movie);
        $this->invalidateAdminMoviesListCache(
            filled($slug) ? '/movies/'.$slug : null,
        );

        return response()->json([
            'success' => true,
            'message' => 'Movie and managed media outputs were deleted.',
            'data' => [
                'deleted_movie' => [
                    'title' => $title,
                    'slug' => $slug,
                ],
            ],
        ]);
    }

    public function updatePublishStatus(
        Request $request,
        Movie $movie,
        PublishScheduleService $publishScheduleService,
        PublishIdempotencyService $idempotencyService,
        ContentChangeLogger $changeLogger,
    ): JsonResponse {
        if ($replay = $idempotencyService->replayIfSeen($request, 'movie:'.$movie->id)) {
            return $replay;
        }

        $validated = Validator::make($request->all(), [
            'publish_status' => ['required', Rule::in(['draft', 'scheduled', 'published'])],
            'scheduled_for' => ['nullable', 'string', 'max:32'],
        ])->validate();

        $publishStatus = $validated['publish_status'];
        $scheduledInput = $publishStatus === 'scheduled'
            ? ($validated['scheduled_for'] ?? $movie->scheduled_for)
            : null;

        if ($publishStatus === 'scheduled' && blank($scheduledInput)) {
            return response()->json([
                'success' => false,
                'message' => 'Scheduled movies require a schedule date.',
                'errors' => [
                    'scheduled_for' => ['A schedule date is required for scheduled movies.'],
                ],
            ], 422);
        }

        $publishState = $publishScheduleService->resolvePublishState(
            $publishStatus,
            $scheduledInput,
            $movie->published_at,
        );

        if (in_array($movie->pipeline_status, ['pending', 'running'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Content processing pipeline is still running. Please wait until it completes.',
            ], 422);
        }

        if ($publishState['publish_status'] === 'published' && ! in_array($movie->processing_status, ['ready'], true)) {
            return response()->json([
                'success' => false,
                'message' => match ($movie->processing_status) {
                    'queued' => 'HLS manifest validation is still running. Please wait until processing completes.',
                    'failed' => 'HLS validation failed. Fix the HLS URL before publishing.',
                    default => 'A manual HLS URL must be set before publishing.',
                },
            ], 422);
        }

        $metadata = is_array($movie->metadata) ? $movie->metadata : [];
        if (isset($metadata['publish']['pending_intent'])) {
            unset($metadata['publish']['pending_intent']);
            if (($metadata['publish'] ?? []) === []) {
                unset($metadata['publish']);
            }
        }

        $movie->update([
            'publish_status' => $publishState['publish_status'],
            'scheduled_for' => $publishState['scheduled_for'],
            'published_at' => $publishState['published_at'],
            'metadata' => $metadata,
        ]);

        if ($publishState['publish_status'] === 'published') {
            $this->catalogCacheService->forgetPublicCollectionsList();
        }

        $movie = $movie->fresh(['mediaAsset.variants', 'mediaAsset.processingRuns', 'creator']);

        if ($publishState['publish_status'] === 'published') {
            $this->catalogContentRankingSyncService->syncOnPublish($movie);
        } else {
            $this->catalogContentRankingSyncService->syncAfterAdminSave($movie);
        }

        $this->invalidateAdminMoviesListCache();
        $publishScheduleService->syncScheduledPublishRunner($movie->fresh());

        $action = match ($publishState['publish_status']) {
            'published' => 'published',
            'scheduled' => 'updated',
            default => 'unpublished',
        };

        $changeLogger->log(
            'movie',
            $movie->id,
            $action,
            $request,
            ['publish_status', 'scheduled_for'],
            null,
            [
                'publish_status' => $publishState['publish_status'],
                'scheduled_for' => $publishState['scheduled_for'],
            ],
        );

        $responseBody = [
            'success' => true,
            'message' => match ($publishState['publish_status']) {
                'published' => 'Movie published successfully.',
                'scheduled' => 'Movie scheduled successfully.',
                default => 'Movie moved back to draft.',
            },
            'data' => [
                'movie' => $this->transformMovie($movie->fresh(['mediaAsset.variants', 'mediaAsset.processingRuns', 'creator']), true),
            ],
        ];

        $idempotencyService->remember($request, 'movie:'.$movie->id, $responseBody);

        return response()->json($responseBody);
    }

    public function previewToken(Movie $movie, ContentPreviewTokenService $previewTokenService): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $previewTokenService->issue($movie),
        ]);
    }

    public function history(Request $request, Movie $movie): JsonResponse
    {
        $page = max(1, (int) $request->integer('page', 1));
        $perPage = min(max((int) $request->integer('per_page', 20), 1), 50);

        $paginator = ContentChangeLog::query()
            ->with('user:id,name,email')
            ->where('entity_type', 'movie')
            ->where('entity_id', $movie->id)
            ->orderByDesc('created_at')
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'success' => true,
            'data' => [
                'history' => $paginator->getCollection()->map(fn (ContentChangeLog $log) => [
                    'id' => $log->id,
                    'action' => $log->action,
                    'changed_fields' => $log->changed_fields ?? [],
                    'old_values' => $log->old_values,
                    'new_values' => $log->new_values,
                    'created_at' => $log->created_at?->toIso8601String(),
                    'user' => $log->user?->only(['id', 'name', 'email']),
                ])->values()->all(),
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                ],
            ],
        ]);
    }

    private function transformMovie(Movie $movie, bool $includeRuns = false): array
    {
        $relations = ['mediaAsset.variants', 'creator'];
        if ($includeRuns) {
            $relations[] = 'castMembers.person';
            $relations[] = 'crewMembers.person';
        }
        $movie->loadMissing($relations);

        $mediaAsset = $movie->mediaAsset;
        $metadata = is_array($movie->metadata) ? $movie->metadata : [];
        $masterPlaylistUrl = $this->resolvePlaylistUrl(
            $mediaAsset?->master_playlist_relative_path,
            data_get($metadata, 'playback.hls_url')
        );
        $variants = $mediaAsset?->variants
            ->sortBy('bandwidth')
            ->values()
            ->map(function ($variant) {
                return [
                    'id' => $variant->id,
                    'profile_key' => $variant->profile_key,
                    'resolution' => $variant->resolution,
                    'bandwidth' => $variant->bandwidth,
                    'playlist_relative_path' => $variant->playlist_relative_path,
                    'playlist_url' => $this->resolvePlaylistUrl($variant->playlist_relative_path),
                    'codecs' => $variant->codecs,
                    'is_active' => $variant->is_active,
                ];
            })
            ->all() ?? [];

        $data = [
            'id' => $movie->id,
            'title' => $movie->title,
            'slug' => $movie->slug,
            'original_title' => $movie->original_title,
            'synopsis' => $movie->synopsis,
            'release_year' => $movie->release_year,
            'duration_seconds' => $movie->duration_seconds,
            'provider_name' => $movie->provider_name,
            'poster_path' => $movie->poster_path,
            'poster_url' => $this->imagePresentationService->resolveMoviePosterUrl($movie->poster_path, $metadata),
            'poster_blur_url' => data_get($this->imagePresentationService->moviePosterImage($metadata), 'blur'),
            'poster_srcset' => data_get($this->imagePresentationService->moviePosterImage($metadata), 'srcset_string'),
            'poster_image' => $this->imagePresentationService->moviePosterImage($metadata),
            'backdrop_url' => $this->imagePresentationService->resolveMovieBackdropUrl($metadata),
            'backdrop_blur_url' => data_get($this->imagePresentationService->movieBackdropImage($metadata), 'blur'),
            'backdrop_srcset' => data_get($this->imagePresentationService->movieBackdropImage($metadata), 'srcset_string'),
            'backdrop_image' => $this->imagePresentationService->movieBackdropImage($metadata),
            'trailer_path' => $movie->trailer_path,
            'trailer_url' => $this->resolveManagedOrExternalUrl($movie->trailer_path, data_get($metadata, 'tmdb.trailer_url')),
            'has_dubbed_audio' => $movie->has_dubbed_audio,
            'dubbed_source_path' => $movie->dubbed_source_path,
            'dubbed_source_url' => $this->resolveManagedOrExternalUrl($movie->dubbed_source_path),
            'audio_tracks' => collect($movie->audio_tracks ?? [])
                ->map(function (array $track) {
                    return [
                        'label' => $track['label'] ?? 'Extra audio',
                        'language' => $track['language'] ?? 'und',
                        'source_path' => $track['source_path'] ?? null,
                        'source_url' => $this->resolveManagedOrExternalUrl($track['source_path'] ?? null),
                    ];
                })
                ->values()
                ->all(),
            'has_subtitle' => $movie->has_subtitle,
            'subtitle_source_path' => $movie->subtitle_source_path,
            'subtitle_source_url' => $this->resolveManagedOrExternalUrl($movie->subtitle_source_path),
            'drm_scheme' => $movie->drm_scheme ?? 'none',
            'drm_license_url' => $movie->drm_license_url,
            'age_rating' => $movie->age_rating,
            'is_featured' => Schema::hasColumn('movies', 'is_featured')
                ? (bool) ($movie->is_featured ?? false)
                : false,
            'exclude_from_slider' => Schema::hasColumn('movies', 'exclude_from_slider')
                ? (bool) ($movie->exclude_from_slider ?? false)
                : false,
            'catalog_visibility' => Schema::hasColumn('movies', 'catalog_visibility')
                ? (string) ($movie->catalog_visibility ?? 'catalog_search')
                : 'catalog_search',
            'cast_members' => $movie->cast_members ?? [],
            'tmdb_id' => $movie->tmdb_id,
            'cast' => $includeRuns
                ? $movie->castMembers->map(function (MovieCast $row) {
                    return [
                        'tmdb_person_id' => $row->person?->tmdb_person_id,
                        'person_id' => $row->person_id,
                        'name' => $row->person?->name,
                        'character' => $row->character_name,
                        'profile_url' => $row->person?->getProfileImageUrl(),
                        'credit_id' => $row->tmdb_credit_id,
                        'order' => $row->cast_order,
                        'known_for_department' => $row->person?->known_for_department ?? 'Acting',
                        'slug' => $row->person?->slug,
                    ];
                })->values()->all()
                : [],
            'crew' => $includeRuns
                ? $movie->crewMembers->map(function (MovieCrew $row) {
                    return [
                        'tmdb_person_id' => $row->person?->tmdb_person_id,
                        'person_id' => $row->person_id,
                        'name' => $row->person?->name,
                        'job' => $row->job,
                        'department' => $row->department,
                        'profile_url' => $row->person?->getProfileImageUrl(),
                        'credit_id' => $row->tmdb_credit_id,
                        'slug' => $row->person?->slug,
                    ];
                })->values()->all()
                : [],
            'publish_status' => $movie->publish_status,
            'pipeline_status' => $movie->pipeline_status ?? 'idle',
            'processing_status' => $movie->processing_status,
            'processing_error' => $movie->processing_error,
            'scheduled_for' => app(PublishScheduleService::class)->formatScheduleForApi($movie->scheduled_for),
            'published_at' => $movie->published_at?->toISOString(),
            'media_folder' => $movie->media_folder,
            'metadata' => [
                'tmdb' => [
                    'query' => data_get($metadata, 'tmdb.query'),
                    'tmdb_id' => data_get($metadata, 'tmdb.tmdb_id'),
                    'imdb_id' => data_get($metadata, 'tmdb.imdb_id'),
                    'poster_url' => data_get($metadata, 'tmdb.poster_url'),
                    'poster_source_url' => data_get($metadata, 'tmdb.poster_source_url'),
                    'backdrop_url' => data_get($metadata, 'tmdb.backdrop_url'),
                    'backdrop_source_url' => data_get($metadata, 'tmdb.backdrop_source_url'),
                    'banner_url' => data_get($metadata, 'tmdb.banner_url'),
                    'banner_source_url' => data_get($metadata, 'tmdb.banner_source_url'),
                    'logo_url' => data_get($metadata, 'tmdb.logo_url'),
                    'logo_source_url' => data_get($metadata, 'tmdb.logo_source_url'),
                    'trailer_url' => data_get($metadata, 'tmdb.trailer_url'),
                    'rating' => data_get($metadata, 'tmdb.rating'),
                    'popularity' => data_get($metadata, 'tmdb.popularity'),
                    'vote_count' => data_get($metadata, 'tmdb.vote_count'),
                    'original_language' => data_get($metadata, 'tmdb.original_language'),
                    'runtime_minutes' => data_get($metadata, 'tmdb.runtime_minutes'),
                    'directors' => data_get($metadata, 'tmdb.directors', []),
                    'writers' => data_get($metadata, 'tmdb.writers', []),
                    'production_status' => data_get($metadata, 'tmdb.production_status'),
                    'budget' => data_get($metadata, 'tmdb.budget'),
                    'revenue' => data_get($metadata, 'tmdb.revenue'),
                    'genres' => data_get($metadata, 'tmdb.genres', []),
                    'tags' => data_get($metadata, 'tmdb.tags', []),
                    'languages' => data_get($metadata, 'tmdb.languages', []),
                    'countries' => data_get($metadata, 'tmdb.countries', []),
                    'actors' => data_get($metadata, 'tmdb.actors', []),
                ],
                'playback' => [
                    'hls_url' => data_get($metadata, 'playback.hls_url'),
                    'dash_url' => data_get($metadata, 'playback.dash_url'),
                    'downloads' => data_get($metadata, 'playback.downloads', []),
                ],
                'seo' => [
                    'meta_title' => data_get($metadata, 'seo.meta_title'),
                    'meta_description' => data_get($metadata, 'seo.meta_description'),
                ],
                'content_warnings' => data_get($metadata, 'content_warnings', []),
                'quality_badges' => data_get($metadata, 'quality_badges', []),
                'admin_notes' => data_get($metadata, 'admin_notes'),
                'images' => data_get($metadata, 'images', []),
            ],
            'admin_notes' => data_get($metadata, 'admin_notes'),
            'publish_completion_pending' => $this->hasPublishCompletionPending($movie, $metadata),
            'created_at' => $movie->created_at?->toISOString(),
            'updated_at' => $movie->updated_at?->toISOString(),
            'created_by' => $movie->creator?->only(['id', 'name', 'email']),
            'media_asset' => $mediaAsset ? [
                'id' => $mediaAsset->id,
                'kind' => $mediaAsset->kind,
                'source_path' => $mediaAsset->source_path,
                'source_is_external' => $mediaAsset->source_is_external,
                'managed_relative_directory' => $mediaAsset->managed_relative_directory,
                'hls_output_relative_directory' => $mediaAsset->hls_output_relative_directory,
                'master_playlist_relative_path' => $mediaAsset->master_playlist_relative_path,
                'selected_hls_profiles' => $mediaAsset->selected_hls_profiles ?? [],
                'master_playlist_url' => $masterPlaylistUrl,
                'processing_status' => $mediaAsset->processing_status,
                'processing_progress_percent' => $mediaAsset->processing_progress_percent !== null
                    ? (float) $mediaAsset->processing_progress_percent
                    : null,
                'processing_eta_seconds' => $mediaAsset->processing_eta_seconds,
                'current_profile_key' => $mediaAsset->current_profile_key,
                'processing_started_at' => $mediaAsset->processing_started_at?->toISOString(),
                'processing_error' => $mediaAsset->processing_error,
                'last_processed_at' => $mediaAsset->last_processed_at?->toISOString(),
                'variants' => $variants,
            ] : null,
        ];

        if ($includeRuns && $mediaAsset) {
            $data['media_asset']['processing_runs'] = $mediaAsset->processingRuns
                ->sortByDesc('id')
                ->take(5)
                ->values()
                ->map(function ($run) {
                    return [
                        'id' => $run->id,
                        'status' => $run->status,
                        'progress_percent' => $run->progress_percent !== null ? (float) $run->progress_percent : null,
                        'eta_seconds' => $run->eta_seconds,
                        'current_profile_key' => $run->current_profile_key,
                        'source_duration_seconds' => $run->source_duration_seconds,
                        'error_message' => $run->error_message,
                        'started_at' => $run->started_at?->toISOString(),
                        'finished_at' => $run->finished_at?->toISOString(),
                        'created_by' => $run->creator?->only(['id', 'name', 'email']),
                    ];
                })
                ->all();
        }

        return $data;
    }

    /**
     * @param  array<int, string|null>  $extraPaths
     */
    private function invalidateAdminMoviesListCache(?string ...$extraPaths): void
    {
        $paths = array_values(array_filter(
            $extraPaths,
            fn ($path) => is_string($path) && filled($path),
        ));

        $this->catalogCacheService->scheduleRebuildAfterContentChange($paths);
    }

    private function transformMovieListItem(Movie $movie): array
    {
        $movie->loadMissing(['creator']);
        $metadata = is_array($movie->metadata) ? $movie->metadata : [];

        return [
            'id' => $movie->id,
            'title' => $movie->title,
            'slug' => $movie->slug,
            'synopsis' => $movie->synopsis,
            'release_year' => $movie->release_year,
            'poster_url' => $this->resolveManagedOrExternalUrl($movie->poster_path, data_get($metadata, 'tmdb.poster_url')),
            'has_dubbed_audio' => $movie->has_dubbed_audio,
            'has_subtitle' => $movie->has_subtitle,
            'publish_status' => $movie->publish_status,
            'pipeline_status' => $movie->pipeline_status ?? 'idle',
            'processing_status' => $movie->processing_status,
            'processing_error' => $movie->processing_error,
            'is_featured' => Schema::hasColumn('movies', 'is_featured')
                ? (bool) ($movie->is_featured ?? false)
                : false,
            'exclude_from_slider' => Schema::hasColumn('movies', 'exclude_from_slider')
                ? (bool) ($movie->exclude_from_slider ?? false)
                : false,
            'catalog_visibility' => Schema::hasColumn('movies', 'catalog_visibility')
                ? (string) ($movie->catalog_visibility ?? 'catalog_search')
                : 'catalog_search',
            'has_admin_notes' => filled(data_get($metadata, 'admin_notes')),
            'publish_completion_pending' => $this->hasPublishCompletionPending($movie, $metadata),
            'created_at' => $movie->created_at?->toISOString(),
            'updated_at' => $movie->updated_at?->toISOString(),
            'created_by' => $movie->creator?->only(['id', 'name', 'email']),
        ];
    }

    private function hasPublishCompletionPending(Movie $movie, array $metadata): bool
    {
        return $movie->publish_status === 'draft'
            && $movie->processing_status === 'ready'
            && filled(data_get($metadata, 'publish.pending_intent.status'));
    }

    private function resolveManagedOrExternalUrl(?string $path, ?string $externalUrl = null): ?string
    {
        if ($path && preg_match('#^https?://#i', $path) === 1) {
            return $path;
        }

        if ($path && str_starts_with($path, '/images/')) {
            return $path;
        }

        return $externalUrl;
    }

    private function resolvePlaylistUrl(?string $playlistPath, ?string $fallbackUrl = null): ?string
    {
        if ($playlistPath && preg_match('#^https?://#i', $playlistPath) === 1) {
            return $playlistPath;
        }

        if ($playlistPath && $this->mediaPathService->isRemoteMediaReference($playlistPath)) {
            return $this->mediaPathService->coerceRemoteMediaUrl($playlistPath);
        }

        return $fallbackUrl;
    }

    /**
     * Only overwrite TMDB media URLs when the publication form explicitly sent them.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, string|null>
     */
    /**
     * @return ?array<string, mixed>
     */
    private function normalizeBelongsToCollectionPayload(mixed $raw): ?array
    {
        if (! is_array($raw) || empty($raw)) {
            return null;
        }

        $tmdbCollectionId = (int) ($raw['tmdb_collection_id'] ?? $raw['id'] ?? 0);
        if ($tmdbCollectionId <= 0) {
            return null;
        }

        return [
            'tmdb_collection_id' => $tmdbCollectionId,
            'name' => filled($raw['name'] ?? null) ? (string) $raw['name'] : null,
            'poster_url' => $raw['poster_url'] ?? null,
            'backdrop_url' => $raw['backdrop_url'] ?? null,
        ];
    }

    private function optionalPublicationMediaMetadata(array $validated): array
    {
        $media = [];

        foreach (['poster_url', 'backdrop_url', 'banner_url', 'logo_url', 'trailer_url'] as $field) {
            if (!array_key_exists($field, $validated)) {
                continue;
            }

            $value = $validated[$field];
            $media[$field] = filled($value) ? trim((string) $value) : null;
        }

        return $media;
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
     * @param  array{labels: array<int, string>, ids: array<int, int>, slugs: array<int, string>}  $alignedGenres
     * @param  array<int, string>  $countryIsos
     * @return array{genre_ids: array<int, string>, country_ids: array<int, string>, network_ids: array<int, string>}
     */
    private function buildFilterIdentifiers(array $alignedGenres, array $countryIsos, ?string $network): array
    {
        return [
            'genre_ids' => array_map('strval', $alignedGenres['ids']),
            'country_ids' => array_values(array_unique(array_map(
                fn (string $iso) => strtoupper($iso),
                $countryIsos,
            ))),
            'network_ids' => $network ? $this->normalizeFilterIdentifiers([$network]) : [],
        ];
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

    public function ingestTmdbImages(Request $request): JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'entity_id' => ['nullable', 'integer', 'exists:movies,id'],
            'poster_url' => ['nullable', 'string', 'max:2048'],
            'backdrop_url' => ['nullable', 'string', 'max:2048'],
            'banner_url' => ['nullable', 'string', 'max:2048'],
            'logo_url' => ['nullable', 'string', 'max:2048'],
            'actors' => ['nullable', 'array', 'max:50'],
            'actors.*.profile_url' => ['nullable', 'string', 'max:2048'],
        ])->validate();

        $data = $this->contentImageIngestionService->ingestTmdbSyncBatch([
            'entity_type' => 'movie',
            'entity_id' => $validated['entity_id'] ?? null,
            'poster_url' => $validated['poster_url'] ?? null,
            'backdrop_url' => $validated['backdrop_url'] ?? null,
            'banner_url' => $validated['banner_url'] ?? null,
            'logo_url' => $validated['logo_url'] ?? null,
            'actors' => $validated['actors'] ?? [],
        ]);

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

}

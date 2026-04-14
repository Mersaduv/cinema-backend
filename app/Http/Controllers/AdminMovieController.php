<?php

namespace App\Http\Controllers;

use App\Models\Country;
use App\Models\Genre;
use App\Models\Language;
use App\Models\Actor;
use App\Jobs\ProcessMovieMediaAssetJob;
use App\Models\MediaAsset;
use App\Models\Movie;
use App\Models\Tag;
use App\Services\Cinema\MediaPathService;
use App\Services\Cinema\MovieCleanupService;
use App\Services\Cinema\MovieDraftUpdateService;
use App\Services\Cinema\MovieIngestionService;
use App\Services\Cinema\HlsTranscodingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class AdminMovieController extends Controller
{
    public function __construct(
        private readonly MovieIngestionService $movieIngestionService,
        private readonly MovieCleanupService $movieCleanupService,
        private readonly MediaPathService $mediaPathService,
        private readonly MovieDraftUpdateService $movieDraftUpdateService,
        private readonly HlsTranscodingService $hlsTranscodingService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $search = trim((string) $request->string('search'));
        $publishStatus = (string) $request->string('publish_status');
        $processingStatus = (string) $request->string('processing_status');
        $perPage = min(max((int) $request->integer('per_page', 12), 1), 50);

        $query = Movie::query()
            ->with(['mediaAsset.variants', 'creator', 'genres', 'countries', 'languages', 'tags', 'actors'])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($innerQuery) use ($search) {
                    $innerQuery
                        ->where('title', 'like', "%{$search}%")
                        ->orWhere('original_title', 'like', "%{$search}%")
                        ->orWhere('slug', 'like', "%{$search}%");
                });
            })
            ->when(in_array($publishStatus, ['draft', 'scheduled', 'published'], true), function ($query) use ($publishStatus) {
                $query->where('publish_status', $publishStatus);
            })
            ->when(in_array($processingStatus, ['pending', 'queued', 'processing', 'ready', 'failed'], true), function ($query) use ($processingStatus) {
                $query->where('processing_status', $processingStatus);
            })
            ->latest();

        $movies = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => [
                'movies' => collect($movies->items())
                    ->map(fn (Movie $movie) => $this->transformMovie($movie))
                    ->values(),
                'summary' => [
                    'total' => Movie::count(),
                    'drafts' => Movie::where('publish_status', 'draft')->count(),
                    'published' => Movie::where('publish_status', 'published')->count(),
                    'processing' => Movie::whereIn('processing_status', ['queued', 'processing'])->count(),
                    'ready' => Movie::where('processing_status', 'ready')->count(),
                ],
                'pagination' => [
                    'current_page' => $movies->currentPage(),
                    'last_page' => $movies->lastPage(),
                    'per_page' => $movies->perPage(),
                    'total' => $movies->total(),
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
                'processing_statuses' => [
                    ['value' => 'pending', 'label' => 'در انتظار'],
                    ['value' => 'queued', 'label' => 'در صف'],
                    ['value' => 'processing', 'label' => 'در حال پردازش'],
                    ['value' => 'ready', 'label' => 'آماده پخش'],
                    ['value' => 'failed', 'label' => 'خطا'],
                ],
                'hls_profiles' => collect((array) config('cinema.hls_profiles', []))
                    ->map(function (array $profile, string $key) {
                        return [
                            'key' => $key,
                            'label' => strtoupper($key),
                            'resolution' => "{$profile['width']}x{$profile['height']}",
                            'bandwidth' => $profile['bandwidth'],
                        ];
                    })
                    ->values(),
                'taxonomies' => [
                    'genres' => $this->taxonomyOptions(Genre::class),
                    'countries' => $this->taxonomyOptions(Country::class),
                    'languages' => $this->taxonomyOptions(Language::class),
                    'tags' => $this->taxonomyOptions(Tag::class),
                    'actors' => $this->taxonomyOptions(Actor::class),
                ],
                'media_root' => $this->mediaPathService->mediaRoot(),
                'upload_limits' => [
                    'upload_max_filesize' => ini_get('upload_max_filesize') ?: null,
                    'post_max_size' => ini_get('post_max_size') ?: null,
                    'max_execution_time' => ini_get('max_execution_time') ?: null,
                    'max_input_time' => ini_get('max_input_time') ?: null,
                ],
            ],
        ]);
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
            'source_path' => ['nullable', 'string', 'max:2048', 'required_without:source_file'],
            'source_file' => ['nullable', 'file', 'mimes:mp4,mkv,mov,avi,m4v,ts', 'required_without:source_path'],
            'publish_status' => ['nullable', Rule::in(['draft', 'scheduled', 'published'])],
            'scheduled_for' => ['nullable', 'date'],
            'start_processing' => ['nullable', 'boolean'],
            'selected_hls_profiles' => ['nullable', 'array'],
            'selected_hls_profiles.*' => ['string', Rule::in(array_keys((array) config('cinema.hls_profiles', [])))],
            'has_dubbed_audio' => ['nullable', 'boolean'],
            'dubbed_source_path' => ['nullable', 'string', 'max:2048'],
            'audio_tracks' => ['nullable', 'array'],
            'audio_tracks.*.label' => ['required_with:audio_tracks.*.source_path', 'string', 'max:120'],
            'audio_tracks.*.language' => ['nullable', 'string', 'max:12'],
            'audio_tracks.*.source_path' => ['required_with:audio_tracks.*.label', 'string', 'max:2048'],
            'has_subtitle' => ['nullable', 'boolean'],
            'subtitle_source_path' => ['nullable', 'string', 'max:2048'],
            'genre_ids' => ['nullable', 'array'],
            'genre_ids.*' => ['integer', Rule::exists('genres', 'id')],
            'country_ids' => ['nullable', 'array'],
            'country_ids.*' => ['integer', Rule::exists('countries', 'id')],
            'language_ids' => ['nullable', 'array'],
            'language_ids.*' => ['integer', Rule::exists('languages', 'id')],
            'tag_ids' => ['nullable', 'array'],
            'tag_ids.*' => ['integer', Rule::exists('tags', 'id')],
            'actor_ids' => ['nullable', 'array'],
            'actor_ids.*' => ['integer', Rule::exists('actors', 'id')],
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
                    'scheduled_for' => ['برای محتوای زمان بندی شده باید تاریخ انتشار تعیین شود.'],
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

        if ((bool) $request->boolean('start_processing') && $movie->mediaAsset) {
            $movie->update(['processing_status' => 'queued']);
            $movie->mediaAsset->update(['processing_status' => 'queued']);

            ProcessMovieMediaAssetJob::dispatch($movie->mediaAsset->id, $request->user()->id);
        }

        return response()->json([
            'success' => true,
            'message' => 'Movie registered successfully.',
            'data' => [
                'movie' => $this->transformMovie($movie->fresh(['mediaAsset.variants', 'mediaAsset.processingRuns', 'creator', 'genres', 'countries', 'languages', 'tags', 'actors'])),
            ],
        ], 201);
    }

    public function updatePublicationForm(Request $request, Movie $movie): JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'title' => ['required', 'string', 'max:255'],
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
            'genre_ids' => ['nullable', 'array'],
            'genre_ids.*' => ['integer', Rule::exists('genres', 'id')],
            'country_ids' => ['nullable', 'array'],
            'country_ids.*' => ['integer', Rule::exists('countries', 'id')],
            'language_ids' => ['nullable', 'array'],
            'language_ids.*' => ['integer', Rule::exists('languages', 'id')],
            'tag_ids' => ['nullable', 'array'],
            'tag_ids.*' => ['integer', Rule::exists('tags', 'id')],
            'actor_ids' => ['nullable', 'array'],
            'actor_ids.*' => ['integer', Rule::exists('actors', 'id')],
        ])->validate();

        try {
            $posterPath = filled($validated['poster_path'] ?? null)
                ? $this->mediaPathService->storeMoviePoster((string) $validated['poster_path'], $movie->slug)
                : null;
            $trailerPath = filled($validated['trailer_path'] ?? null)
                ? $this->mediaPathService->storeMovieTrailer((string) $validated['trailer_path'], $movie->slug)
                : null;
            $dubbedSourcePath = filled($validated['dubbed_source_path'] ?? null)
                ? $this->mediaPathService->storeMovieDubbedSource((string) $validated['dubbed_source_path'], $movie->slug)
                : null;
            $audioTracks = collect($validated['audio_tracks'] ?? [])
                ->filter(fn (array $track) => filled($track['source_path'] ?? null))
                ->values()
                ->map(function (array $track, int $index) use ($movie) {
                    return [
                        'label' => trim((string) ($track['label'] ?? ('صوت ' . ($index + 2)))),
                        'language' => trim((string) ($track['language'] ?? 'und')) ?: 'und',
                        'source_path' => $this->mediaPathService->storeManagedMovieFile(
                            (string) $track['source_path'],
                            $movie->slug,
                            'audio',
                            'track-' . ($index + 2)
                        ),
                    ];
                })
                ->all();
            $subtitleSourcePath = filled($validated['subtitle_source_path'] ?? null)
                ? $this->mediaPathService->storeMovieSubtitle((string) $validated['subtitle_source_path'], $movie->slug)
                : null;
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'errors' => [
                    'media' => [$exception->getMessage()],
                ],
            ], 422);
        }

        $shouldSyncSupplementalTracks =
            $movie->dubbed_source_path !== ((bool) ($validated['has_dubbed_audio'] ?? false) ? $dubbedSourcePath : null)
            || $movie->has_dubbed_audio !== (bool) ($validated['has_dubbed_audio'] ?? false)
            || $movie->subtitle_source_path !== ((bool) ($validated['has_subtitle'] ?? false) ? $subtitleSourcePath : null)
            || $movie->has_subtitle !== (bool) ($validated['has_subtitle'] ?? false)
            || json_encode($movie->audio_tracks ?? []) !== json_encode($audioTracks);

        $movie->update([
            'title' => $validated['title'],
            'poster_path' => $posterPath,
            'trailer_path' => $trailerPath,
            'has_dubbed_audio' => (bool) ($validated['has_dubbed_audio'] ?? false),
            'dubbed_source_path' => (bool) ($validated['has_dubbed_audio'] ?? false) ? $dubbedSourcePath : null,
            'audio_tracks' => $audioTracks,
            'has_subtitle' => (bool) ($validated['has_subtitle'] ?? false),
            'subtitle_source_path' => (bool) ($validated['has_subtitle'] ?? false) ? $subtitleSourcePath : null,
            'synopsis' => $validated['synopsis'] ?? null,
            'age_rating' => $validated['age_rating'] ?? null,
            'cast_members' => Actor::query()
                ->whereIn('id', $validated['actor_ids'] ?? [])
                ->orderBy('name')
                ->pluck('name')
                ->values()
                ->all(),
        ]);

        $movie->genres()->sync($validated['genre_ids'] ?? []);
        $movie->countries()->sync($validated['country_ids'] ?? []);
        $movie->languages()->sync($validated['language_ids'] ?? []);
        $movie->tags()->sync($validated['tag_ids'] ?? []);
        $movie->actors()->sync($validated['actor_ids'] ?? []);

        if ($shouldSyncSupplementalTracks && $movie->mediaAsset && $movie->mediaAsset->processing_status === 'ready') {
            try {
                $this->hlsTranscodingService->syncSupplementalTracks($movie->mediaAsset->fresh(['variants', 'mediable']));
            } catch (\Throwable $exception) {
                return response()->json([
                    'success' => false,
                    'message' => 'اطلاعات ذخیره شد اما هماهنگ‌سازی دوبله/زیرنویس با پلیر انجام نشد: ' . $exception->getMessage(),
                    'errors' => [
                        'media' => [$exception->getMessage()],
                    ],
                ], 422);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Publication form saved successfully.',
            'data' => [
                'movie' => $this->transformMovie(
                    $movie->fresh(['mediaAsset.variants', 'mediaAsset.processingRuns', 'creator', 'genres', 'countries', 'languages', 'tags', 'actors']),
                    true
                ),
            ],
        ]);
    }

    public function show(Movie $movie): JsonResponse
    {
        $movie->load(['mediaAsset.variants', 'mediaAsset.processingRuns.creator', 'creator', 'genres', 'countries', 'languages', 'tags', 'actors']);

        return response()->json([
            'success' => true,
            'data' => [
                'movie' => $this->transformMovie($movie, true),
            ],
        ]);
    }

    public function updateDraftForm(Request $request, Movie $movie): JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'title' => ['required', 'string', 'max:255'],
            'original_title' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', Rule::unique('movies', 'slug')->ignore($movie->id)],
            'release_year' => ['nullable', 'integer', 'between:1888,2100'],
            'provider_name' => ['nullable', 'string', 'max:255'],
            'source_path' => ['required', 'string', 'max:2048'],
            'selected_hls_profiles' => ['nullable', 'array'],
            'selected_hls_profiles.*' => ['string', Rule::in(array_keys((array) config('cinema.hls_profiles', [])))],
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
            'genre_ids' => ['nullable', 'array'],
            'genre_ids.*' => ['integer', Rule::exists('genres', 'id')],
            'country_ids' => ['nullable', 'array'],
            'country_ids.*' => ['integer', Rule::exists('countries', 'id')],
            'language_ids' => ['nullable', 'array'],
            'language_ids.*' => ['integer', Rule::exists('languages', 'id')],
            'tag_ids' => ['nullable', 'array'],
            'tag_ids.*' => ['integer', Rule::exists('tags', 'id')],
            'actor_ids' => ['nullable', 'array'],
            'actor_ids.*' => ['integer', Rule::exists('actors', 'id')],
        ])->validate();

        try {
            $updatedMovie = $this->movieDraftUpdateService->updateMovie($movie, $validated);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'errors' => [
                    'movie' => [$exception->getMessage()],
                ],
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Draft movie updated successfully.',
            'data' => [
                'movie' => $this->transformMovie($updatedMovie, true),
            ],
        ]);
    }

    public function queueProcessing(Request $request, Movie $movie): JsonResponse
    {
        $movie->load('mediaAsset');

        if (!$movie->mediaAsset instanceof MediaAsset) {
            return response()->json([
                'success' => false,
                'message' => 'No media asset is registered for this movie.',
            ], 404);
        }

        $validated = Validator::make($request->all(), [
            'selected_hls_profiles' => ['nullable', 'array'],
            'selected_hls_profiles.*' => ['string', Rule::in(array_keys((array) config('cinema.hls_profiles', [])))],
        ])->validate();

        $this->hlsTranscodingService->clearQueuedJobs($movie->mediaAsset);

        $movie->update([
            'processing_status' => 'queued',
            'processing_error' => null,
        ]);

        $movie->mediaAsset->update([
            'processing_status' => 'queued',
            'processing_error' => null,
            'selected_hls_profiles' => $validated['selected_hls_profiles'] ?? $movie->mediaAsset->selected_hls_profiles ?? array_keys((array) config('cinema.hls_profiles', [])),
            'stop_requested' => false,
        ]);

        ProcessMovieMediaAssetJob::dispatch($movie->mediaAsset->id, $request->user()->id);

        return response()->json([
            'success' => true,
            'message' => 'Movie processing has been queued.',
            'data' => [
                'movie' => $this->transformMovie($movie->fresh(['mediaAsset.variants', 'mediaAsset.processingRuns'])),
            ],
        ], 202);
    }

    public function stopProcessing(Movie $movie): JsonResponse
    {
        $movie->load('mediaAsset');

        if (!$movie->mediaAsset instanceof MediaAsset) {
            return response()->json([
                'success' => false,
                'message' => 'No media asset is registered for this movie.',
            ], 404);
        }

        if (!in_array($movie->processing_status, ['queued', 'processing'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'This movie is not currently processing.',
            ], 422);
        }

        $this->hlsTranscodingService->stop($movie->mediaAsset);

        return response()->json([
            'success' => true,
            'message' => 'Movie processing was stopped and partial outputs were cleaned.',
            'data' => [
                'movie' => $this->transformMovie($movie->fresh(['mediaAsset.variants', 'mediaAsset.processingRuns', 'creator', 'genres', 'countries', 'languages', 'tags', 'actors']), true),
            ],
        ]);
    }

    public function destroy(Movie $movie): JsonResponse
    {
        $title = $movie->title;
        $slug = $movie->slug;

        $this->movieCleanupService->deleteMovie($movie);

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

    public function updatePublishStatus(Request $request, Movie $movie): JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'publish_status' => ['required', Rule::in(['draft', 'published'])],
        ])->validate();

        $publishStatus = $validated['publish_status'];

        if (
            $publishStatus === 'published'
            && $movie->processing_status !== 'ready'
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Only processed movies can be published.',
            ], 422);
        }

        $movie->update([
            'publish_status' => $publishStatus,
            'published_at' => $publishStatus === 'published' ? now() : null,
        ]);

        return response()->json([
            'success' => true,
            'message' => $publishStatus === 'published'
                ? 'Movie published successfully.'
                : 'Movie moved back to draft.',
            'data' => [
                'movie' => $this->transformMovie($movie->fresh(['mediaAsset.variants', 'mediaAsset.processingRuns', 'creator', 'genres', 'countries', 'languages', 'tags', 'actors']), true),
            ],
        ]);
    }

    private function transformMovie(Movie $movie, bool $includeRuns = false): array
    {
        $movie->loadMissing(['mediaAsset.variants', 'creator', 'genres', 'countries', 'languages', 'tags', 'actors']);

        $mediaAsset = $movie->mediaAsset;
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
                    'playlist_url' => $this->mediaPathService->toPublicUrl($variant->playlist_relative_path),
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
            'poster_url' => $this->mediaPathService->inferPublicUrlFromAbsolutePath($movie->poster_path),
            'trailer_path' => $movie->trailer_path,
            'trailer_url' => $this->mediaPathService->inferPublicUrlFromAbsolutePath($movie->trailer_path),
            'has_dubbed_audio' => $movie->has_dubbed_audio,
            'dubbed_source_path' => $movie->dubbed_source_path,
            'dubbed_source_url' => $this->mediaPathService->inferPublicUrlFromAbsolutePath($movie->dubbed_source_path),
            'audio_tracks' => collect($movie->audio_tracks ?? [])
                ->map(function (array $track) {
                    return [
                        'label' => $track['label'] ?? 'صوت اضافه',
                        'language' => $track['language'] ?? 'und',
                        'source_path' => $track['source_path'] ?? null,
                        'source_url' => $this->mediaPathService->inferPublicUrlFromAbsolutePath($track['source_path'] ?? null),
                    ];
                })
                ->values()
                ->all(),
            'has_subtitle' => $movie->has_subtitle,
            'subtitle_source_path' => $movie->subtitle_source_path,
            'subtitle_source_url' => $this->mediaPathService->inferPublicUrlFromAbsolutePath($movie->subtitle_source_path),
            'age_rating' => $movie->age_rating,
            'cast_members' => $movie->cast_members ?? [],
            'publish_status' => $movie->publish_status,
            'processing_status' => $movie->processing_status,
            'processing_error' => $movie->processing_error,
            'scheduled_for' => $movie->scheduled_for?->toISOString(),
            'published_at' => $movie->published_at?->toISOString(),
            'media_folder' => $movie->media_folder,
            'media_folder_absolute' => $this->mediaPathService->toAbsolutePath($movie->media_folder),
            'created_at' => $movie->created_at?->toISOString(),
            'updated_at' => $movie->updated_at?->toISOString(),
            'created_by' => $movie->creator?->only(['id', 'name', 'email']),
            'taxonomies' => [
                'genres' => $movie->genres->map(fn ($item) => $this->transformTaxonomyReference($item))->values()->all(),
                'countries' => $movie->countries->map(fn ($item) => $this->transformTaxonomyReference($item))->values()->all(),
                'languages' => $movie->languages->map(fn ($item) => $this->transformTaxonomyReference($item))->values()->all(),
                'tags' => $movie->tags->map(fn ($item) => $this->transformTaxonomyReference($item))->values()->all(),
                'actors' => $movie->actors->map(fn ($item) => $this->transformTaxonomyReference($item))->values()->all(),
            ],
            'media_asset' => $mediaAsset ? [
                    'id' => $mediaAsset->id,
                    'kind' => $mediaAsset->kind,
                    'source_path' => $mediaAsset->source_path,
                    'source_is_external' => $mediaAsset->source_is_external,
                'managed_relative_directory' => $mediaAsset->managed_relative_directory,
                'hls_output_relative_directory' => $mediaAsset->hls_output_relative_directory,
                'hls_output_absolute_directory' => $this->mediaPathService->toAbsolutePath($mediaAsset->hls_output_relative_directory),
                    'master_playlist_relative_path' => $mediaAsset->master_playlist_relative_path,
                    'selected_hls_profiles' => $mediaAsset->selected_hls_profiles ?? array_keys((array) config('cinema.hls_profiles', [])),
                    'master_playlist_url' => $this->mediaPathService->toPublicUrl($mediaAsset->master_playlist_relative_path),
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
            $data['media_asset']['processing_runs'] = $mediaAsset->processingRuns()
                ->latest()
                ->get()
                ->map(function ($run) {
                    return [
                        'id' => $run->id,
                        'status' => $run->status,
                        'progress_percent' => $run->progress_percent !== null ? (float) $run->progress_percent : null,
                        'eta_seconds' => $run->eta_seconds,
                        'current_profile_key' => $run->current_profile_key,
                        'source_duration_seconds' => $run->source_duration_seconds,
                        'error_message' => $run->error_message,
                        'ffmpeg_command' => $run->ffmpeg_command,
                        'logs' => $run->logs,
                        'started_at' => $run->started_at?->toISOString(),
                        'finished_at' => $run->finished_at?->toISOString(),
                        'created_by' => $run->creator?->only(['id', 'name', 'email']),
                    ];
                })
                ->values()
                ->all();
        }

        return $data;
    }

    /**
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $modelClass
     * @return array<int, array<string, mixed>>
     */
    private function taxonomyOptions(string $modelClass): array
    {
        $columns = ['id', 'name', 'slug'];

        if ($modelClass === Actor::class) {
            $columns[] = 'image_path';
        }

        return $modelClass::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get($columns)
            ->map(fn ($item) => $this->transformTaxonomyReference($item))
            ->values()
            ->all();
    }

    private function transformTaxonomyReference($item): array
    {
        return [
            'id' => $item->id,
            'name' => $item->name,
            'slug' => $item->slug,
            'image_url' => property_exists($item, 'image_path') || isset($item->image_path)
                ? $this->mediaPathService->inferPublicUrlFromAbsolutePath($item->image_path)
                : null,
        ];
    }
}

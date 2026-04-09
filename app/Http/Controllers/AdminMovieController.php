<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessMovieMediaAssetJob;
use App\Models\MediaAsset;
use App\Models\Movie;
use App\Services\Cinema\MediaPathService;
use App\Services\Cinema\MovieIngestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class AdminMovieController extends Controller
{
    public function __construct(
        private readonly MovieIngestionService $movieIngestionService,
        private readonly MediaPathService $mediaPathService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $search = trim((string) $request->string('search'));
        $publishStatus = (string) $request->string('publish_status');
        $processingStatus = (string) $request->string('processing_status');
        $perPage = min(max((int) $request->integer('per_page', 12), 1), 50);

        $query = Movie::query()
            ->with(['mediaAsset.variants', 'creator'])
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
                'media_root' => $this->mediaPathService->mediaRoot(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', Rule::unique('movies', 'slug')],
            'original_title' => ['nullable', 'string', 'max:255'],
            'synopsis' => ['nullable', 'string'],
            'release_year' => ['nullable', 'integer', 'between:1888,2100'],
            'duration_seconds' => ['nullable', 'integer', 'min:1'],
            'provider_name' => ['nullable', 'string', 'max:255'],
            'poster_path' => ['nullable', 'string', 'max:2048'],
            'source_path' => ['required', 'string', 'max:2048'],
            'publish_status' => ['required', Rule::in(['draft', 'scheduled', 'published'])],
            'scheduled_for' => ['nullable', 'date'],
            'start_processing' => ['nullable', 'boolean'],
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
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'errors' => [
                    'source_path' => [$exception->getMessage()],
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
                'movie' => $this->transformMovie($movie->fresh(['mediaAsset.variants', 'mediaAsset.processingRuns', 'creator'])),
            ],
        ], 201);
    }

    public function show(Movie $movie): JsonResponse
    {
        $movie->load(['mediaAsset.variants', 'mediaAsset.processingRuns.creator', 'creator']);

        return response()->json([
            'success' => true,
            'data' => [
                'movie' => $this->transformMovie($movie, true),
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

        $movie->update([
            'processing_status' => 'queued',
            'processing_error' => null,
        ]);

        $movie->mediaAsset->update([
            'processing_status' => 'queued',
            'processing_error' => null,
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

    private function transformMovie(Movie $movie, bool $includeRuns = false): array
    {
        $movie->loadMissing(['mediaAsset.variants', 'creator']);

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
            'media_asset' => $mediaAsset ? [
                'id' => $mediaAsset->id,
                'kind' => $mediaAsset->kind,
                'source_path' => $mediaAsset->source_path,
                'source_is_external' => $mediaAsset->source_is_external,
                'managed_relative_directory' => $mediaAsset->managed_relative_directory,
                'hls_output_relative_directory' => $mediaAsset->hls_output_relative_directory,
                'hls_output_absolute_directory' => $this->mediaPathService->toAbsolutePath($mediaAsset->hls_output_relative_directory),
                'master_playlist_relative_path' => $mediaAsset->master_playlist_relative_path,
                'master_playlist_url' => $this->mediaPathService->toPublicUrl($mediaAsset->master_playlist_relative_path),
                'processing_status' => $mediaAsset->processing_status,
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
}

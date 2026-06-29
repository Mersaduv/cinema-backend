<?php

namespace App\Http\Controllers;

use App\Models\Collection;
use App\Models\Movie;
use App\Services\Cinema\CatalogCacheService;
use App\Services\Cinema\CollectionService;
use App\Services\Cinema\ContentImageIngestionService;
use App\Services\Cinema\ImagePresentationService;
use App\Services\Cinema\NextPublicCacheRevalidationService;
use App\Support\CatalogListResponseMapper;
use App\Support\CinemaValidation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class AdminCollectionController extends Controller
{
    public function __construct(
        private readonly CollectionService $collectionService,
        private readonly ContentImageIngestionService $contentImageIngestionService,
        private readonly ImagePresentationService $imagePresentationService,
        private readonly CatalogListResponseMapper $catalogListResponseMapper,
        private readonly CatalogCacheService $catalogCacheService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->integer('page', 1));
        $perPage = min(max((int) $request->integer('per_page', 12), 1), 50);
        $search = trim((string) $request->string('search'));
        $visibility = $request->string('visibility');

        $query = Collection::query()
            ->withCount('movies')
            ->when($search !== '', function ($builder) use ($search) {
                $builder->where(function ($inner) use ($search) {
                    $inner
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('slug', 'like', "%{$search}%");
                });
            })
            ->when($visibility === 'visible', fn ($builder) => $builder->where('is_visible', true))
            ->when($visibility === 'hidden', fn ($builder) => $builder->where('is_visible', false))
            ->orderBy('sort_order')
            ->orderByDesc('updated_at');

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'success' => true,
            'data' => [
                'collections' => collect($paginator->items())
                    ->map(fn (Collection $item) => $this->transformListItem($item))
                    ->values(),
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                ],
            ],
        ]);
    }

    public function show(Collection $collection): JsonResponse
    {
        $collection->load([
            'movies' => fn ($query) => $query->with(['genres']),
            'creator',
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'collection' => $this->transformCollection($collection, true),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request);

        $collection = $this->collectionService->createManual($validated, $request->user());
        $this->invalidatePublicCollectionsCache();

        return response()->json([
            'success' => true,
            'message' => 'Collection created successfully.',
            'data' => [
                'collection' => $this->transformCollection($collection->fresh(['movies']), true),
            ],
        ], 201);
    }

    public function update(Request $request, Collection $collection): JsonResponse
    {
        $validated = $this->validatePayload($request, $collection);

        $collection = $this->collectionService->updateManual($collection, $validated);
        $this->invalidatePublicCollectionsCache();

        return response()->json([
            'success' => true,
            'message' => 'Collection updated successfully.',
            'data' => [
                'collection' => $this->transformCollection($collection->fresh(['movies']), true),
            ],
        ]);
    }

    public function syncMovies(Request $request, Collection $collection): JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'movies' => ['required', 'array'],
            'movies.*.id' => ['required', 'integer', 'exists:movies,id'],
            'movies.*.sort_order' => ['nullable', 'integer', 'min:0'],
        ])->validate();

        $this->collectionService->syncMovies($collection, $validated['movies']);
        $this->invalidatePublicCollectionsCache();

        return response()->json([
            'success' => true,
            'message' => 'Collection movies synced successfully.',
            'data' => [
                'collection' => $this->transformCollection($collection->fresh(['movies']), true),
            ],
        ]);
    }

    public function destroy(Collection $collection): JsonResponse
    {
        $collection->delete();
        $this->invalidatePublicCollectionsCache();

        return response()->json([
            'success' => true,
            'message' => 'Collection deleted successfully.',
        ]);
    }

    public function ingestTmdbImages(Request $request): JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'entity_type' => ['required', 'string', Rule::in(['collection'])],
            'entity_id' => ['nullable', 'integer', 'min:1'],
            'poster_url' => CinemaValidation::nullableImageOrUrl(),
            'backdrop_url' => CinemaValidation::nullableImageOrUrl(),
        ])->validate();

        $batch = $this->contentImageIngestionService->ingestTmdbSyncBatch($validated);

        return response()->json([
            'success' => true,
            'data' => $batch,
        ]);
    }

  /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, ?Collection $collection = null): array
    {
        return Validator::make($request->all(), [
            'name' => [$collection ? 'sometimes' : 'required', 'string', 'max:255'],
            'slug' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('collections', 'slug')->ignore($collection?->id),
            ],
            'tmdb_collection_id' => ['nullable', 'integer', 'min:1'],
            'description' => ['nullable', 'string'],
            'poster_url' => CinemaValidation::nullableImageOrUrl(),
            'backdrop_url' => CinemaValidation::nullableImageOrUrl(),
            'is_visible' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'metadata' => ['nullable', 'array'],
            'movies' => ['nullable', 'array'],
            'movies.*.id' => ['required_with:movies', 'integer', 'exists:movies,id'],
            'movies.*.sort_order' => ['nullable', 'integer', 'min:0'],
        ])->validate();
    }

    private function transformListItem(Collection $collection): array
    {
        $metadata = is_array($collection->metadata) ? $collection->metadata : [];

        return [
            'id' => $collection->id,
            'name' => $collection->name,
            'slug' => $collection->slug,
            'tmdb_collection_id' => $collection->tmdb_collection_id,
            'description' => $collection->description,
            'poster_url' => $this->imagePresentationService->resolveCollectionPosterUrl(
                $collection->poster_url,
                $metadata,
            ),
            'backdrop_url' => $this->imagePresentationService->resolveCollectionBackdropUrl(
                $collection->backdrop_url,
                $metadata,
            ),
            'poster_image' => $this->imagePresentationService->collectionPosterImage($metadata),
            'backdrop_image' => $this->imagePresentationService->collectionBackdropImage($metadata),
            'is_visible' => (bool) $collection->is_visible,
            'sort_order' => (int) $collection->sort_order,
            'movies_count' => (int) ($collection->movies_count ?? $collection->movies()->count()),
            'updated_at' => $collection->updated_at?->toIso8601String(),
        ];
    }

    private function transformCollection(Collection $collection, bool $includeMovies = false): array
    {
        $item = $this->transformListItem($collection);

        if ($includeMovies) {
            $genreMap = $this->catalogListResponseMapper->movieGenreMap(
                $collection->movies->pluck('id')->map(fn ($id) => (int) $id)->all(),
            );

            $item['movies'] = $collection->movies
                ->map(function (Movie $movie) use ($genreMap) {
                    return array_merge(
                        [
                            'publish_status' => $movie->publish_status,
                            'sort_order' => (int) ($movie->pivot?->sort_order ?? 0),
                        ],
                        $this->catalogListResponseMapper->publicMovieListItem([
                            'id' => $movie->id,
                            'title' => $movie->title,
                            'slug' => $movie->slug,
                            'synopsis' => $movie->synopsis,
                            'release_year' => $movie->release_year,
                            'has_subtitle' => $movie->has_subtitle,
                            'has_dubbed_audio' => $movie->has_dubbed_audio,
                            'poster_path' => $movie->poster_path,
                            'metadata' => $movie->metadata,
                            'filter_rating' => $movie->filter_rating,
                        ], $genreMap),
                    );
                })
                ->values()
                ->all();
        }

        return $item;
    }

    private function invalidatePublicCollectionsCache(): void
    {
        $this->catalogCacheService->forgetPublicCollectionsList();
        app(NextPublicCacheRevalidationService::class)->revalidateCatalog(['/collections']);
    }
}

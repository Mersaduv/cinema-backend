<?php

namespace App\Http\Controllers;

use App\Models\Collection;
use App\Models\Movie;
use App\Services\Cinema\CatalogCacheService;
use App\Services\Cinema\ImagePresentationService;
use App\Support\CatalogListResponseMapper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class PublicCollectionController extends Controller
{
    public function __construct(
        private readonly CatalogListResponseMapper $catalogListResponseMapper,
        private readonly ImagePresentationService $imagePresentationService,
        private readonly CatalogCacheService $catalogCacheService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->integer('page', 1));
        $perPage = min(max((int) $request->integer('per_page', 24), 1), 48);
        $search = trim((string) $request->string('search'));

        $cacheKey = $this->catalogCacheService->listCacheKey(
            'collections_list',
            md5(serialize([$page, $perPage, $search])),
        );

        $payload = Cache::remember($cacheKey, 300, function () use ($search, $page, $perPage) {
            $query = Collection::query()
                ->visible()
                ->withCount([
                    'movies as published_movies_count' => fn ($builder) => $builder
                        ->where('publish_status', 'published'),
                ])
                ->when($search !== '', function ($builder) use ($search) {
                    $builder->where(function ($inner) use ($search) {
                        $inner
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('description', 'like', "%{$search}%");
                    });
                })
                ->orderBy('sort_order')
                ->orderBy('name');

            $paginator = $query->paginate($perPage, ['*'], 'page', $page);

            $collections = collect($paginator->items())
                ->map(fn (Collection $item) => $this->publicListItem($item))
                ->values()
                ->all();

            return [
                'collections' => $collections,
                'pagination' => [
                    'total' => $paginator->total(),
                    'per_page' => $paginator->perPage(),
                    'current_page' => $paginator->currentPage(),
                    'last_page' => max(1, $paginator->lastPage()),
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

    public function show(Collection $collection): JsonResponse
    {
        if (! $collection->is_visible) {
            return response()->json([
                'success' => false,
                'message' => 'Collection is not available.',
            ], 404);
        }

        $collection->load([
            'movies' => fn ($query) => $query
                ->where('publish_status', 'published')
                ->with(['genres']),
        ]);

        return response()
            ->json([
                'success' => true,
                'data' => [
                    'collection' => $this->publicDetailItem($collection),
                ],
            ])
            ->withHeaders([
                'Cache-Control' => 'public, max-age=300, stale-while-revalidate=60',
                'Vary' => 'Accept-Encoding',
            ]);
    }

    private function publicListItem(Collection $collection): array
    {
        $metadata = is_array($collection->metadata) ? $collection->metadata : [];
        $posterImage = $this->imagePresentationService->collectionPosterImage($metadata);
        $backdropImage = $this->imagePresentationService->collectionBackdropImage($metadata);

        return [
            'id' => $collection->id,
            'name' => $collection->name,
            'slug' => $collection->slug,
            'description' => $collection->description,
            'poster_url' => $this->imagePresentationService->resolveCollectionPosterUrl(
                $collection->poster_url,
                $metadata,
            ),
            'poster_blur_url' => data_get($posterImage, 'blur'),
            'poster_srcset' => data_get($posterImage, 'srcset_string'),
            'poster_image' => $posterImage,
            'backdrop_url' => $this->imagePresentationService->resolveCollectionBackdropUrl(
                $collection->backdrop_url,
                $metadata,
            ),
            'backdrop_blur_url' => data_get($backdropImage, 'blur'),
            'backdrop_srcset' => data_get($backdropImage, 'srcset_string'),
            'backdrop_image' => $backdropImage,
            'movies_count' => (int) ($collection->published_movies_count ?? $collection->movies()->published()->count()),
        ];
    }

    private function publicDetailItem(Collection $collection): array
    {
        $item = $this->publicListItem($collection);
        $genreMap = $this->catalogListResponseMapper->movieGenreMap(
            $collection->movies->pluck('id')->map(fn ($id) => (int) $id)->all(),
        );

        $item['movies'] = $collection->movies
            ->map(function (Movie $movie) use ($genreMap) {
                return $this->catalogListResponseMapper->publicMovieListItem([
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
                ], $genreMap);
            })
            ->values()
            ->all();

        return $item;
    }
}

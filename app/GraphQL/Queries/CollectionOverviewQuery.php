<?php

namespace App\GraphQL\Queries;

use App\Models\Collection;
use App\Models\Movie;
use App\Services\Cinema\ImagePresentationService;
use App\Support\CatalogListResponseMapper;
use GraphQL\Type\Definition\ResolveInfo;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class CollectionOverviewQuery
{
    public function __construct(
        private readonly CatalogListResponseMapper $catalogListResponseMapper,
        private readonly ImagePresentationService $imagePresentationService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(mixed $root, array $args, GraphQLContext $context, ResolveInfo $resolveInfo): array
    {
        $collection = Collection::query()
            ->visible()
            ->where('slug', $args['slug'])
            ->with([
                'movies' => fn ($query) => $query
                    ->where('publish_status', 'published')
                    ->with(['genres']),
            ])
            ->firstOrFail();

        $metadata = is_array($collection->metadata) ? $collection->metadata : [];
        $posterImage = $this->imagePresentationService->collectionPosterImage($metadata);
        $backdropImage = $this->imagePresentationService->collectionBackdropImage($metadata);
        $genreMap = $this->catalogListResponseMapper->movieGenreMap(
            $collection->movies->pluck('id')->map(fn ($id) => (int) $id)->all(),
        );

        return [
            'id' => $collection->id,
            'slug' => $collection->slug,
            'name' => $collection->name,
            'description' => $collection->description,
            'poster_url' => $this->imagePresentationService->resolveCollectionPosterUrl(
                $collection->poster_url,
                $metadata,
            ),
            'backdrop_url' => $this->imagePresentationService->resolveCollectionBackdropUrl(
                $collection->backdrop_url,
                $metadata,
            ),
            'poster_image' => $posterImage,
            'backdrop_image' => $backdropImage,
            'movies' => $collection->movies
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
                ->all(),
        ];
    }
}

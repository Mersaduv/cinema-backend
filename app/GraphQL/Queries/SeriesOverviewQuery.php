<?php

namespace App\GraphQL\Queries;

use App\GraphQL\Support\FieldSelection;
use App\Models\Series;
use App\Services\Cinema\PublicContentCacheService;
use App\Services\Cinema\SeriesPublicPresenter;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Support\Facades\Cache;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class SeriesOverviewQuery
{
    public function __construct(
        private readonly SeriesPublicPresenter $presenter,
        private readonly PublicContentCacheService $contentCacheService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function __invoke(mixed $root, array $args, GraphQLContext $context, ResolveInfo $resolveInfo): array
    {
        $slug = (string) $args['slug'];
        $selection = FieldSelection::tree($resolveInfo, 2);
        $include = $this->includeFromSelection($selection);
        $fingerprint = FieldSelection::fingerprint($selection);
        $cacheKey = $this->contentCacheService->seriesGraphqlOverviewKey($slug, $fingerprint);
        $ttl = $this->contentCacheService->detailTtl();

        return Cache::remember($cacheKey, $ttl, function () use ($slug, $include) {
            $with = [];

            if ($include['genre_tags'] || ($include['details'] ?? false)) {
                $with[] = 'genres';
            }

            if ($include['cast'] || $include['details_actors']) {
                $with[] = 'castMembers.person';
            }

            if ($include['crew']) {
                $with[] = 'crewMembers.person';
            }

            if ($include['seasons']) {
                $with[] = 'seasons';
            }

            $series = Series::query()
                ->published()
                ->where('slug', $slug)
                ->when($with !== [], fn ($query) => $query->with($with))
                ->firstOrFail();

            return $this->presenter->overview($series, $include);
        });
    }

    /**
     * @param  array<string, mixed>  $selection
     * @return array{
     *   cast: bool,
     *   crew: bool,
     *   seasons: bool,
     *   details: bool,
     *   details_actors: bool,
     *   details_gallery: bool,
     *   genre_tags: bool,
     *   poster_image: bool,
     *   backdrop_image: bool,
     *   has_subtitle: bool,
     *   has_dubbed: bool
     * }
     */
    private function includeFromSelection(array $selection): array
    {
        $details = is_array($selection['details'] ?? null) ? $selection['details'] : [];

        return [
            'cast' => FieldSelection::wants($selection, 'cast'),
            'crew' => FieldSelection::wants($selection, 'crew'),
            'seasons' => FieldSelection::wants($selection, 'seasons'),
            'details' => FieldSelection::wants($selection, 'details'),
            'details_actors' => array_key_exists('actors', $details),
            'details_gallery' => array_key_exists('gallery', $details),
            'genre_tags' => FieldSelection::wants($selection, 'genre_tags')
                || array_key_exists('genres', $details),
            'poster_image' => FieldSelection::wants($selection, 'poster_image')
                || FieldSelection::wants($selection, 'poster_blur_url')
                || FieldSelection::wants($selection, 'poster_srcset'),
            'backdrop_image' => FieldSelection::wants($selection, 'backdrop_image')
                || FieldSelection::wants($selection, 'backdrop_blur_url')
                || FieldSelection::wants($selection, 'backdrop_srcset'),
            'has_subtitle' => FieldSelection::wants($selection, 'has_subtitle'),
            'has_dubbed' => FieldSelection::wants($selection, 'has_dubbed'),
        ];
    }
}

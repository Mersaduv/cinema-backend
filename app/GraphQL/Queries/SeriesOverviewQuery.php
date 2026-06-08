<?php

namespace App\GraphQL\Queries;

use App\Models\Series;
use App\Services\Cinema\SeriesPublicPresenter;
use GraphQL\Type\Definition\ResolveInfo;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class SeriesOverviewQuery
{
    public function __construct(
        private readonly SeriesPublicPresenter $presenter,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function __invoke(mixed $root, array $args, GraphQLContext $context, ResolveInfo $resolveInfo): array
    {
        $series = Series::query()
            ->published()
            ->where('slug', $args['slug'])
            ->with([
                'genres',
                'castMembers.person',
                'crewMembers.person',
                'seasons',
            ])
            ->firstOrFail();

        return $this->presenter->overview($series);
    }
}

<?php

namespace App\GraphQL\Queries;

use App\Models\Series;
use App\Services\Cinema\ContentPreviewTokenService;
use App\Services\Cinema\SeriesPublicPresenter;
use GraphQL\Error\Error;
use GraphQL\Type\Definition\ResolveInfo;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class SeriesOverviewPreviewQuery
{
    public function __construct(
        private readonly ContentPreviewTokenService $previewTokenService,
        private readonly SeriesPublicPresenter $presenter,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function __invoke(mixed $root, array $args, GraphQLContext $context, ResolveInfo $resolveInfo): array
    {
        $series = Series::query()->where('slug', $args['slug'])->first();

        if (! $series) {
            throw new Error('Series not found.');
        }

        $result = $this->previewTokenService->validateAndConsume($series, $args['token'] ?? null);

        if (! $result['valid']) {
            throw new Error('Preview token is invalid or expired.');
        }

        $series->load([
            'genres',
            'castMembers.person',
            'crewMembers.person',
            'seasons',
        ]);

        // Do not fresh() — that drops eager loads and forces N+1 on cast/crew images.
        return $this->presenter->overview($series);
    }
}

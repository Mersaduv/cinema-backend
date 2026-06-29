<?php

namespace App\GraphQL\Queries;

use App\Models\Movie;
use App\Services\Cinema\ContentPreviewTokenService;
use GraphQL\Error\Error;
use GraphQL\Type\Definition\ResolveInfo;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class MoviePreviewQuery
{
    public function __construct(
        private readonly ContentPreviewTokenService $previewTokenService,
    ) {}

    public function __invoke(mixed $root, array $args, GraphQLContext $context, ResolveInfo $resolveInfo): Movie
    {
        $movie = Movie::query()->where('slug', $args['slug'])->first();

        if (! $movie) {
            throw new Error('Movie not found.');
        }

        $result = $this->previewTokenService->validateAndConsume($movie, $args['token'] ?? null);

        if (! $result['valid']) {
            throw new Error('Preview token is invalid or expired.');
        }

        return $movie->fresh(['castMembers.person', 'crewMembers.person', 'genres']);
    }
}

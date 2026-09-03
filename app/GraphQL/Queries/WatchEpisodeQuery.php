<?php

namespace App\GraphQL\Queries;

use App\Services\Cinema\WatchPlaybackService;
use GraphQL\Type\Definition\ResolveInfo;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class WatchEpisodeQuery
{
    public function __construct(
        private readonly WatchPlaybackService $watchPlayback,
    ) {}

    public function __invoke(mixed $root, array $args, GraphQLContext $context, ResolveInfo $resolveInfo): array
    {
        return $this->watchPlayback->episodeWatchPayload(
            (string) $args['seriesSlug'],
            (int) $args['season'],
            (int) $args['episode'],
        );
    }
}

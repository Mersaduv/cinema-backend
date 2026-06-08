<?php

namespace App\GraphQL\Queries;

use App\Models\Series;
use GraphQL\Type\Definition\ResolveInfo;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class WatchEpisodeQuery
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context, ResolveInfo $resolveInfo): array
    {
        $series = Series::query()
            ->where('slug', $args['seriesSlug'])
            ->published()
            ->with(['seasons.episodes'])
            ->firstOrFail();

        $season = $series->seasons->firstWhere('season_number', (int) $args['season']);
        abort_unless($season !== null, 404, 'Season not found');

        $episode = $season->episodes->firstWhere('episode_number', (int) $args['episode']);
        abort_unless($episode !== null, 404, 'Episode not found');

        $allEpisodes = $season->episodes->sortBy('episode_number')->values();
        $idx = $allEpisodes->search(fn ($item) => $item->id === $episode->id);
        $next = $idx !== false ? $allEpisodes->slice((int) $idx + 1, 1)->first() : null;
        $prev = $idx !== false && (int) $idx > 0 ? $allEpisodes->slice((int) $idx - 1, 1)->first() : null;

        return [
            'hls_url' => (string) ($episode->hls_url ?? ''),
            'subtitle_url' => $episode->subtitle_url,
            'dubbed_audio_url' => $episode->dubbed_audio_url,
            'episode' => $episode,
            'series' => [
                'id' => $series->id,
                'slug' => $series->slug,
                'title' => $series->title,
                'poster_url' => $series->poster_url,
                'publish_status' => $series->publish_status,
                'show_type' => $series->show_type,
            ],
            'next_episode' => $next,
            'prev_episode' => $prev,
        ];
    }
}

<?php

namespace App\Services\Cinema;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

/**
 * Newest episode badge per series for home "تازه‌های سینما" cards.
 */
class SeriesLatestEpisodeLookup
{
    /**
     * @param  list<int>  $seriesIds
     * @return array<int, array{season_number: int, episode_number: int, arrived_at: string|null}>
     */
    public function forSeriesIds(array $seriesIds): array
    {
        $seriesIds = array_values(array_unique(array_filter(
            array_map(static fn ($id) => (int) $id, $seriesIds),
            static fn (int $id) => $id > 0,
        )));

        if ($seriesIds === []) {
            return [];
        }

        $arrival = new EpisodeArrival();
        $column = 'e.'.$arrival->column();

        $ranked = DB::table('series_episodes as e')
            ->select('e.id', 'e.series_id', 'e.season_number', 'e.episode_number')
            ->selectRaw($column.' as arrived_at')
            ->selectRaw(
                'ROW_NUMBER() OVER (PARTITION BY e.series_id ORDER BY '.$column.' DESC, e.id DESC) as episode_rank',
            )
            ->whereIn('e.series_id', $seriesIds)
            ->where('e.season_number', '>', 0)
            ->where('e.episode_number', '>', 0)
            ->whereNotNull($column);

        $arrival->constrainPlayable($ranked, 'e');

        $rows = DB::query()
            ->fromSub($ranked, 'latest')
            ->where('episode_rank', 1)
            ->get(['series_id', 'season_number', 'episode_number', 'arrived_at']);

        $map = [];

        foreach ($rows as $row) {
            $seriesId = (int) $row->series_id;
            $arrivedAt = $row->arrived_at ?? null;
            $map[$seriesId] = [
                'season_number' => (int) $row->season_number,
                'episode_number' => (int) $row->episode_number,
                'arrived_at' => filled($arrivedAt)
                    ? Carbon::parse((string) $arrivedAt)->toISOString()
                    : null,
            ];
        }

        return $map;
    }
}

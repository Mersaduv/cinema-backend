<?php

namespace App\Services\Cinema;

use Illuminate\Support\Facades\DB;

/**
 * Newest episode badge per series for home "تازه‌های سینما" cards.
 */
class SeriesLatestEpisodeLookup
{
    /**
     * @param  list<int>  $seriesIds
     * @return array<int, array{season_number: int, episode_number: int}>
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

        $ranked = DB::table('series_episodes')
            ->select('id', 'series_id', 'season_number', 'episode_number')
            ->selectRaw(
                'ROW_NUMBER() OVER (PARTITION BY series_id ORDER BY created_at DESC, season_number DESC, episode_number DESC, id DESC) as episode_rank',
            )
            ->whereIn('series_id', $seriesIds)
            ->where('season_number', '>', 0)
            ->where('episode_number', '>', 0);

        $rows = DB::query()
            ->fromSub($ranked, 'latest')
            ->where('episode_rank', 1)
            ->get(['series_id', 'season_number', 'episode_number']);

        $map = [];

        foreach ($rows as $row) {
            $seriesId = (int) $row->series_id;
            $map[$seriesId] = [
                'season_number' => (int) $row->season_number,
                'episode_number' => (int) $row->episode_number,
            ];
        }

        return $map;
    }
}

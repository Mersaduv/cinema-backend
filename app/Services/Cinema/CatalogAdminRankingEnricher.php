<?php

namespace App\Services\Cinema;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class CatalogAdminRankingEnricher
{
    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  class-string<Model>  $modelClass
     * @return array<int, array<string, mixed>>
     */
    public function enrich(array $rows, string $modelClass): array
    {
        if ($rows === []) {
            return $rows;
        }

        /** @var Model $model */
        $model = new $modelClass();
        $table = $model->getTable();

        if (! Schema::hasColumn($table, 'filter_popularity')) {
            return $rows;
        }

        $ids = array_map(fn (array $row): int => (int) ($row['id'] ?? 0), $rows);
        $rankingById = $modelClass::query()
            ->whereIn('id', $ids)
            ->get([
                'id',
                'filter_popularity',
                'vote_count',
                'trending_rank',
                'internal_popularity_30d',
            ])
            ->keyBy('id');

        return array_map(function (array $row) use ($rankingById): array {
            $ranking = $rankingById->get((int) ($row['id'] ?? 0));

            if (! $ranking) {
                return $row;
            }

            $row['filter_popularity'] = $ranking->filter_popularity;
            $row['vote_count'] = $ranking->vote_count;
            $row['trending_rank'] = $ranking->trending_rank;
            $row['internal_popularity_30d'] = $ranking->internal_popularity_30d;

            return $row;
        }, $rows);
    }
}

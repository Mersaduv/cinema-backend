<?php

namespace App\Services\Cinema;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stable "this episode was uploaded" time.
 * Set once when the episode first becomes playable and never moved by later edits.
 */
class EpisodeArrival
{
    public function column(): string
    {
        return Schema::hasColumn('series_episodes', 'arrived_at') ? 'arrived_at' : 'created_at';
    }

    public function resolve(
        ?string $existingArrivedAt,
        bool $wasPlayable,
        bool $isPlayable,
        ?string $createdAt,
        string $now,
    ): ?string {
        if ($existingArrivedAt !== null && trim($existingArrivedAt) !== '') {
            return $existingArrivedAt;
        }

        if (! $isPlayable) {
            return null;
        }

        if ($wasPlayable && $createdAt !== null && trim($createdAt) !== '') {
            return $createdAt;
        }

        return $now;
    }

    public function isPlayable(?string $sourceMethod, ?string $videoUrl, ?string $embedUrl, ?string $hlsUrl): bool
    {
        $method = strtolower(trim((string) $sourceMethod));

        if (in_array($method, ['url', 'upload', 'video'], true) && $this->hasText($videoUrl)) {
            return true;
        }

        if ($method === 'embed' && $this->hasText($embedUrl)) {
            return true;
        }

        return $this->hasText($hlsUrl);
    }

    private function hasText(?string $value): bool
    {
        return $value !== null && trim($value) !== '';
    }

    public function constrainPlayable(Builder $query, string $alias = 'series_episodes'): void
    {
        $query->where(function (Builder $playable) use ($alias): void {
            $playable->where(function (Builder $progressive) use ($alias): void {
                $progressive->whereIn($alias.'.source_method', ['url', 'upload', 'video'])
                    ->whereNotNull($alias.'.video_url')
                    ->where($alias.'.video_url', '!=', '');
            })->orWhere(function (Builder $embed) use ($alias): void {
                $embed->where($alias.'.source_method', 'embed')
                    ->whereNotNull($alias.'.embed_url')
                    ->where($alias.'.embed_url', '!=', '');
            })->orWhere(function (Builder $hls) use ($alias): void {
                $hls->whereNotNull($alias.'.hls_url')
                    ->where($alias.'.hls_url', '!=', '');
            });
        });
    }

    public function latestArrivedAtQuery(string $seriesColumn = 'series.id'): Builder
    {
        $column = $this->column();
        $query = DB::table('series_episodes as e')
            ->select('e.'.$column)
            ->whereColumn('e.series_id', $seriesColumn)
            ->whereNotNull('e.'.$column)
            ->orderByDesc('e.'.$column)
            ->orderByDesc('e.id')
            ->limit(1);

        $this->constrainPlayable($query, 'e');

        return $query;
    }
}

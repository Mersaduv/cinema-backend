<?php

namespace App\GraphQL\Types;

use App\Models\SeriesEpisode as SeriesEpisodeModel;

final class SeriesEpisode
{
    public function durationSeconds(SeriesEpisodeModel $episode): ?int
    {
        return $episode->duration_minutes !== null
            ? (int) $episode->duration_minutes * 60
            : null;
    }
}

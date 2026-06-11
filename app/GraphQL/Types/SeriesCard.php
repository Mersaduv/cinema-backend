<?php

namespace App\GraphQL\Types;

use App\Models\Series as SeriesModel;
use App\Services\Cinema\SeriesPublicPresenter;

/** Lightweight list card — metadata only, no episode scans. */
final class SeriesCard
{
    public function __construct(
        private readonly SeriesPublicPresenter $presenter,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function listMeta(SeriesModel $series): array
    {
        return $this->presenter->listMeta($series);
    }
}

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
     * @return array{status_label_fa: ?string, genres: list<string>, rating: mixed, detail_backdrop_url: ?string}
     */
    public function listMeta(SeriesModel $series): array
    {
        return [
            'status_label_fa' => $this->presenter->statusLabelFa($series->status),
            'genres' => $this->presenter->genres($series),
            'rating' => $this->presenter->rating($series),
            'detail_backdrop_url' => $this->presenter->detailBackdropUrl($series),
        ];
    }
}

<?php

namespace App\GraphQL\Types;

use App\Models\Movie as MovieModel;
use App\Services\Cinema\MoviePublicPresenter;

/** Lightweight catalog card — one list_meta resolver per row. */
final class MovieCard
{
    public function __construct(
        private readonly MoviePublicPresenter $presenter,
    ) {}

    /**
     * @return array{poster_url: ?string, backdrop_url: ?string, genres: list<string>, rating: mixed}
     */
    public function listMeta(MovieModel $movie): array
    {
        return $this->presenter->listMeta($movie);
    }
}

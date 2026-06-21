<?php

namespace App\Services\Cinema;

use App\Models\Genre;
use App\Models\ProductionCountry;
use App\Models\SpokenLanguage;
use App\Support\CatalogGenreManifest;
use Illuminate\Support\Facades\Cache;

class AdminReferenceOptionsService
{
    /** @var array<string, array<string, mixed>> */
    private static array $memCache = [];

    /**
     * @return array<int, array<string, mixed>>
     */
    public function genres(): array
    {
        return $this->cachedReferenceRows()['genres'];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function countries(): array
    {
        return $this->cachedReferenceRows()['countries'];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function languages(): array
    {
        return $this->cachedReferenceRows()['languages'];
    }

    public function invalidate(): void
    {
        self::$memCache = [];
        Cache::forget('admin_reference_options_v1');
    }

    /**
     * @return array{
     *     genres: array<int, array<string, mixed>>,
     *     countries: array<int, array<string, mixed>>,
     *     languages: array<int, array<string, mixed>>
     * }
     */
    private function cachedReferenceRows(): array
    {
        if (isset(self::$memCache['opts'])) {
            return self::$memCache['opts'];
        }

        $data = Cache::remember('admin_reference_options_v1', 86400, function (): array {
            CatalogGenreManifest::ensureCatalogGenresExist();

            return [
                'genres' => collect(CatalogGenreManifest::CATALOG_ROWS)
                    ->map(function (array $row) {
                        $genre = $row['tmdb_id'] !== null
                            ? Genre::query()->where('tmdb_id', $row['tmdb_id'])->first()
                            : Genre::query()->where('slug', $row['slug'])->first();

                        if (! $genre instanceof Genre) {
                            return null;
                        }

                        return [
                            'id' => $genre->id,
                            'tmdb_id' => $genre->tmdb_id,
                            'name' => $row['name'],
                            'slug' => $genre->slug,
                            'type' => $genre->type,
                        ];
                    })
                    ->filter()
                    ->values()
                    ->all(),
                'countries' => ProductionCountry::query()
                    ->orderBy('name')
                    ->get(['iso_3166_1', 'name', 'flag_emoji'])
                    ->map(fn ($row) => [
                        'iso' => $row->iso_3166_1,
                        'name' => $row->name,
                        'flag' => $row->flag_emoji,
                    ])
                    ->values()
                    ->all(),
                'languages' => SpokenLanguage::query()
                    ->orderBy('name')
                    ->get(['iso_639_1', 'name'])
                    ->map(fn ($row) => [
                        'iso' => $row->iso_639_1,
                        'name' => $row->name,
                    ])
                    ->values()
                    ->all(),
            ];
        });

        self::$memCache['opts'] = $data;

        return $data;
    }
}

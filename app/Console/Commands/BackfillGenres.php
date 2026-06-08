<?php

namespace App\Console\Commands;

use App\Models\Movie;
use App\Models\ProductionCountry;
use App\Models\Series;
use App\Services\Cinema\GenreCatalogService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class BackfillGenres extends Command
{
    protected $signature = 'cinema:backfill-genres';

    protected $description = 'Backfill movie_genre/series_genre pivots and origin fields from metadata JSON';

    public function handle(GenreCatalogService $genreCatalogService): int
    {
        if (! Schema::hasTable('genres')) {
            $this->error('Run migrations first: php artisan migrate');

            return self::FAILURE;
        }

        $movieCount = 0;
        $seriesCount = 0;

        Movie::query()->chunkById(50, function ($movies) use ($genreCatalogService, &$movieCount): void {
            foreach ($movies as $movie) {
                $metadata = is_array($movie->metadata) ? $movie->metadata : [];
                $rawGenres = data_get($metadata, 'tmdb.genres', []);
                if (is_array($rawGenres) && $rawGenres !== []) {
                    $genreCatalogService->syncMovieGenres($movie, $rawGenres);
                }

                $updates = $this->filterFieldUpdates($metadata, $movie->age_rating, 'movies');
                if ($updates !== []) {
                    $movie->update($updates);
                }

                $movieCount++;
            }
        });

        Series::query()->chunkById(50, function ($seriesItems) use ($genreCatalogService, &$seriesCount): void {
            foreach ($seriesItems as $series) {
                $metadata = is_array($series->metadata) ? $series->metadata : [];
                $rawGenres = data_get($metadata, 'tmdb.genres', []);
                if (is_array($rawGenres) && $rawGenres !== []) {
                    $genreCatalogService->syncSeriesGenres($series, $rawGenres);
                }

                $updates = $this->filterFieldUpdates($metadata, null, 'series');
                if ($updates !== []) {
                    $series->update($updates);
                }

                $seriesCount++;
            }
        });

        $this->info("✓ Backfilled {$movieCount} movies and {$seriesCount} series");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function filterFieldUpdates(array $metadata, ?string $ageRating, string $table): array
    {
        $updates = [];

        $iso = $this->resolveOriginCountryIso(data_get($metadata, 'tmdb.countries', []));
        if ($iso !== null) {
            $updates['origin_country'] = $iso;
        }

        $lang = data_get($metadata, 'tmdb.original_language');
        if (is_string($lang) && $lang !== '') {
            $updates['original_language'] = strtolower(substr($lang, 0, 10));
        }

        $rating = data_get($metadata, 'tmdb.rating');
        if ($rating !== null && $rating !== '' && Schema::hasColumn($table, 'filter_rating')) {
            $updates['filter_rating'] = round((float) $rating, 1);
        }

        $contentRating = data_get($metadata, 'tmdb.content_rating') ?? $ageRating;
        if (filled($contentRating)) {
            $updates['content_rating'] = (string) $contentRating;
        }

        return $updates;
    }

    /**
     * @param  mixed  $countries
     */
    private function resolveOriginCountryIso($countries): ?string
    {
        if (! is_array($countries) || $countries === []) {
            return null;
        }

        $first = $countries[0];
        if (! is_string($first)) {
            return null;
        }

        $first = trim($first);
        if (strlen($first) === 2) {
            return strtoupper($first);
        }

        $country = ProductionCountry::query()
            ->where('iso_3166_1', strtoupper($first))
            ->orWhere('name', $first)
            ->orWhere('native_name', $first)
            ->first();

        return $country?->iso_3166_1;
    }
}

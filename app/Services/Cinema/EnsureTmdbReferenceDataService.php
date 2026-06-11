<?php

namespace App\Services\Cinema;

use App\Models\ProductionCountry;
use Database\Seeders\TmdbCountryFallbackSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

class EnsureTmdbReferenceDataService
{
    private const FULL_COUNTRY_THRESHOLD = 200;

    public const SYNC_LOCK_KEY = 'tmdb_countries_sync_scheduled';

    public function ensureCountriesLoaded(): void
    {
        $count = ProductionCountry::query()->count();

        if ($count >= self::FULL_COUNTRY_THRESHOLD) {
            return;
        }

        if ($count === 0) {
            (new TmdbCountryFallbackSeeder())->run();
            $count = ProductionCountry::query()->count();
        }

        if (
            $count < self::FULL_COUNTRY_THRESHOLD
            && $this->tmdbCredentialsConfigured()
            && ! Cache::has(self::SYNC_LOCK_KEY)
        ) {
            Cache::put(self::SYNC_LOCK_KEY, true, 3600);
            $this->scheduleFullTmdbSync();
        }
    }

    private function tmdbCredentialsConfigured(): bool
    {
        return (bool) (config('services.tmdb.api_key') || config('services.tmdb.access_token'));
    }

    private function scheduleFullTmdbSync(): void
    {
        if (app()->environment('testing')) {
            return;
        }

        dispatch(static function (): void {
            Artisan::call('cinema:sync-tmdb-reference-data', ['--force' => true]);
        })->afterResponse();
    }
}

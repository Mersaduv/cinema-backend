<?php

namespace App\Console\Commands;

use App\Models\Genre;
use App\Models\ProductionCountry;
use App\Models\SpokenLanguage;
use App\Services\Cinema\CatalogCacheService;
use App\Services\Cinema\EnsureTmdbReferenceDataService;
use Illuminate\Support\Facades\Cache;
use App\Support\CatalogGenreManifest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SyncTmdbReferenceData extends Command
{
    protected $signature = 'cinema:sync-tmdb-reference-data {--force : Re-sync even if data exists}';

    protected $description = 'Sync TMDB genres, countries, and languages into local reference tables';

    public function handle(CatalogCacheService $catalogCache): int
    {
        $language = config('services.tmdb.default_language', 'fa-IR');
        $baseUrl = rtrim((string) config('services.tmdb.base_url'), '/');
        $apiKey = config('services.tmdb.api_key');
        $token = config('services.tmdb.access_token');

        if (! $apiKey && ! $token) {
            $this->error('TMDB API credentials are missing.');

            return self::FAILURE;
        }

        $movieGenreCount = $this->syncMovieGenres($baseUrl, $language, $apiKey, $token);
        $tvGenreCount = $this->syncTvGenres($baseUrl, $language, $apiKey, $token);
        $countryCount = $this->syncCountries($baseUrl, $language, $apiKey, $token);
        $languageCount = $this->syncLanguages($baseUrl, $apiKey, $token);
        CatalogGenreManifest::ensureCatalogGenresExist();

        $catalogCache->forgetSharedReferenceData();
        Cache::forget(EnsureTmdbReferenceDataService::SYNC_LOCK_KEY);

        $this->info("✓ {$movieGenreCount} movie genres, {$tvGenreCount} TV genres, {$countryCount} countries, {$languageCount} languages synced");

        return self::SUCCESS;
    }

    private function syncMovieGenres(string $baseUrl, string $language, ?string $apiKey, ?string $token): int
    {
        $items = $this->tmdbGet($baseUrl, '/genre/movie/list', ['language' => $language], $apiKey, $token);
        $count = 0;

        foreach ($items['genres'] ?? [] as $row) {
            $this->upsertGenre((int) $row['id'], (string) $row['name'], 'movie');
            $count++;
        }

        return $count;
    }

    private function syncTvGenres(string $baseUrl, string $language, ?string $apiKey, ?string $token): int
    {
        $items = $this->tmdbGet($baseUrl, '/genre/tv/list', ['language' => $language], $apiKey, $token);
        $count = 0;

        foreach ($items['genres'] ?? [] as $row) {
            $existing = Genre::query()->where('tmdb_id', (int) $row['id'])->first();
            if ($existing) {
                $existing->update([
                    'name' => (string) $row['name'],
                    'type' => $existing->type === 'movie' ? 'both' : 'tv',
                ]);
            } else {
                $this->upsertGenre((int) $row['id'], (string) $row['name'], 'tv');
            }
            $count++;
        }

        return $count;
    }

    private function syncCountries(string $baseUrl, string $language, ?string $apiKey, ?string $token): int
    {
        $items = $this->tmdbGet($baseUrl, '/configuration/countries', ['language' => $language], $apiKey, $token);
        $count = 0;

        foreach ($items as $row) {
            if (! is_array($row)) {
                continue;
            }
            $iso = strtoupper((string) ($row['iso_3166_1'] ?? ''));
            if ($iso === '') {
                continue;
            }

            ProductionCountry::query()->updateOrCreate(
                ['iso_3166_1' => $iso],
                [
                    'name' => $this->preferredCountryName($row, $iso),
                    'native_name' => $row['native_name'] ?? null,
                    'english_name' => $row['english_name'] ?? null,
                    'flag_emoji' => $this->flagForIso($iso),
                ],
            );
            $count++;
        }

        return $count;
    }

    private function syncLanguages(string $baseUrl, ?string $apiKey, ?string $token): int
    {
        $items = $this->tmdbGet($baseUrl, '/configuration/languages', [], $apiKey, $token);
        $count = 0;

        foreach ($items as $row) {
            if (! is_array($row)) {
                continue;
            }
            $iso = strtolower((string) ($row['iso_639_1'] ?? ''));
            if ($iso === '') {
                continue;
            }

            SpokenLanguage::query()->updateOrCreate(
                ['iso_639_1' => $iso],
                [
                    'name' => $this->preferredLanguageName($row, $iso),
                    'english_name' => (string) ($row['english_name'] ?? $row['name'] ?? $iso),
                ],
            );
            $count++;
        }

        return $count;
    }

    private function upsertGenre(int $tmdbId, string $name, string $type): void
    {
        $slug = Str::slug($name) ?: 'genre-'.$tmdbId;
        $existing = Genre::query()->where('tmdb_id', $tmdbId)->first();

        if ($existing) {
            $existing->update([
                'name' => $name,
                'type' => $this->mergeType($existing->type, $type),
            ]);

            return;
        }

        $baseSlug = $slug;
        $counter = 2;
        while (Genre::query()->where('slug', $slug)->where('tmdb_id', '!=', $tmdbId)->exists()) {
            $slug = "{$baseSlug}-{$counter}";
            $counter++;
        }

        Genre::query()->create([
            'tmdb_id' => $tmdbId,
            'name' => $name,
            'slug' => $slug,
            'type' => $type,
        ]);
    }

    private function mergeType(string $existing, string $incoming): string
    {
        if ($existing === $incoming || $existing === 'both' || $incoming === 'both') {
            return 'both';
        }

        return 'both';
    }

    /**
     * @return array<string, mixed>|array<int, mixed>
     */
    private function tmdbGet(string $baseUrl, string $path, array $query, ?string $apiKey, ?string $token): array
    {
        $request = Http::timeout(20)->acceptJson();
        if (! config('services.tmdb.verify_ssl', true)) {
            $request = $request->withoutVerifying();
        }
        if ($token) {
            $request = $request->withToken($token);
        } else {
            $query['api_key'] = $apiKey;
        }

        $response = $request->get($baseUrl.$path, $query);
        $response->throw();

        return $response->json() ?? [];
    }

    private function preferredLanguageName(array $row, string $iso): string
    {
        $native = trim((string) ($row['name'] ?? ''));
        $english = trim((string) ($row['english_name'] ?? ''));

        if ($native !== '' && ! preg_match('/^[\?\s]+$/u', $native)) {
            return $native;
        }

        if ($english !== '') {
            return $english;
        }

        return $iso;
    }

    private function preferredCountryName(array $row, string $iso): string
    {
        foreach (['native_name', 'english_name'] as $key) {
            $value = trim((string) ($row[$key] ?? ''));
            if ($value !== '' && ! preg_match('/^[\?\s]+$/u', $value)) {
                return $value;
            }
        }

        return $iso;
    }

    private function flagForIso(string $iso): ?string
    {
        $iso = strtoupper($iso);
        if (strlen($iso) !== 2) {
            return null;
        }

        return mb_chr(ord($iso[0]) - 65 + 0x1F1E6).mb_chr(ord($iso[1]) - 65 + 0x1F1E6);
    }
}

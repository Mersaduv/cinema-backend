<?php

namespace App\Services\Cinema;

use App\Models\Genre;
use App\Models\Movie;
use App\Models\Series;
use App\Support\CatalogGenreManifest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GenreCatalogService
{
    public static function normalizeGenreLabel(string $name): string
    {
        $name = trim($name);
        $name = preg_replace('/[\s\-‑–_\x{200C}\x{200D}]+/u', ' ', $name) ?? $name;

        return trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
    }

    /**
     * @return array{slug: string, name: string, tmdb_id: int|null, type: string}|null
     */
    public function manifestRowForLabel(string $name): ?array
    {
        $normalized = self::normalizeGenreLabel($name);

        foreach (CatalogGenreManifest::CATALOG_ROWS as $row) {
            if (self::normalizeGenreLabel($row['name']) === $normalized) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @return array{slug: string, name: string, tmdb_id: int|null, type: string}|null
     */
    public function manifestRowForTmdbId(int $tmdbId): ?array
    {
        foreach (CatalogGenreManifest::CATALOG_ROWS as $row) {
            if (($row['tmdb_id'] ?? null) === $tmdbId) {
                return $row;
            }
        }

        return null;
    }

    public function resolveGenreRecordForLabel(string $name, string $defaultType): Genre
    {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Genre name cannot be empty.');
        }

        $manifestRow = $this->manifestRowForLabel($name);
        if ($manifestRow !== null) {
            $manifestType = $manifestRow['type'] === 'both' ? $defaultType : $manifestRow['type'];

            if ($manifestRow['tmdb_id'] !== null) {
                return $this->upsertFromTmdb(
                    $manifestRow['tmdb_id'],
                    $manifestRow['name'],
                    $manifestType,
                );
            }

            $bySlug = Genre::query()->where('slug', $manifestRow['slug'])->first();
            if ($bySlug instanceof Genre) {
                return $bySlug;
            }
        }

        $exactMatches = Genre::query()->where('name', $name)->get();
        if ($exactMatches->isNotEmpty()) {
            return $this->pickPreferredGenre($exactMatches);
        }

        $normalized = self::normalizeGenreLabel($name);
        $normalizedMatches = Genre::query()
            ->get()
            ->filter(fn (Genre $genre) => self::normalizeGenreLabel($genre->name) === $normalized);

        if ($normalizedMatches->isNotEmpty()) {
            return $this->pickPreferredGenre($normalizedMatches);
        }

        $record = Genre::create([
            'name' => $manifestRow['name'] ?? $name,
            'slug' => $this->uniqueSlug($manifestRow['name'] ?? $name),
            'type' => $defaultType,
        ]);
        Log::warning('Genre auto-created without TMDB id', ['name' => $record->name, 'type' => $defaultType]);

        return $record;
    }

    public function canonicalizeGenre(Genre $genre): Genre
    {
        return $this->resolveGenreRecordForLabel($genre->name, $genre->type);
    }

    /**
     * @param  array<int, int>  $genreIds
     * @return array<int, int>
     */
    public function expandGenreIdsForFilter(array $genreIds): array
    {
        if ($genreIds === []) {
            return [];
        }

        $expanded = collect($genreIds);

        Genre::query()
            ->whereIn('id', $genreIds)
            ->get()
            ->each(function (Genre $genre) use (&$expanded): void {
                $canonical = $this->canonicalizeGenre($genre);
                $expanded->push($canonical->id);

                if ($canonical->tmdb_id !== null) {
                    $expanded = $expanded->merge(
                        Genre::query()->where('tmdb_id', $canonical->tmdb_id)->pluck('id'),
                    );
                }

                $normalized = self::normalizeGenreLabel($canonical->name);
                $expanded = $expanded->merge(
                    Genre::query()
                        ->get()
                        ->filter(fn (Genre $candidate) => self::normalizeGenreLabel($candidate->name) === $normalized)
                        ->pluck('id'),
                );
            });

        return $expanded->unique()->map(fn ($id) => (int) $id)->values()->all();
    }

    /**
     * @return array<string, string>
     */
    public function buildGenreIdAliasMap(): array
    {
        $aliases = [];

        Genre::query()
            ->get(['id', 'name', 'type', 'tmdb_id', 'slug', 'display_order'])
            ->each(function (Genre $genre) use (&$aliases): void {
                $canonical = $this->canonicalizeGenre($genre);
                $canonicalId = (string) $canonical->id;
                $genreId = (string) $genre->id;

                if ($genreId !== $canonicalId) {
                    $aliases[$genreId] = $canonicalId;
                }

                if ($genre->slug !== '' && $genre->slug !== $canonical->slug) {
                    $aliases[$genre->slug] = $canonicalId;
                }
            });

        return $aliases;
    }

    /**
     * @param  Collection<int, Genre>  $candidates
     */
    private function pickPreferredGenre(Collection $candidates): Genre
    {
        return $candidates
            ->sortByDesc(fn (Genre $genre) => ($genre->tmdb_id !== null ? 1_000_000 : 0) + (int) ($genre->display_order ?? 0))
            ->first();
    }

    /**
     * @param  array<int, array{id?: int, name?: string}>|array<int, string>  $tmdbGenres
     * @return array<int, int>
     */
    public function resolveGenreIdsFromTmdbPayload(array $tmdbGenres, string $defaultType = 'movie'): array
    {
        $ids = [];
        $seenRecords = [];

        foreach ($tmdbGenres as $genre) {
            if (is_string($genre)) {
                $name = trim($genre);
                if ($name === '') {
                    continue;
                }

                $record = $this->resolveGenreRecordForLabel($name, $defaultType);
                if (isset($seenRecords[$record->id])) {
                    continue;
                }
                $seenRecords[$record->id] = true;
                $ids[] = $record->id;

                continue;
            }

            if (! is_array($genre)) {
                continue;
            }

            $tmdbId = isset($genre['id']) ? (int) $genre['id'] : null;
            $name = trim((string) ($genre['name'] ?? ''));

            if ($tmdbId === null && $name === '') {
                continue;
            }

            $record = $tmdbId !== null
                ? $this->upsertFromTmdb($tmdbId, $name, $defaultType)
                : $this->resolveGenreRecordForLabel($name, $defaultType);
            if (isset($seenRecords[$record->id])) {
                continue;
            }
            $seenRecords[$record->id] = true;
            $ids[] = $record->id;
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  array<int, array{id?: int, name?: string}|string>  $rawGenres
     * @return array<int, array{id: int|null, name: string, slug: string}>
     */
    public function enrichGenresForTmdbPayload(array $rawGenres, string $type): array
    {
        return app(GenreAlignmentService::class)->align($rawGenres, $type)['refs'];
    }

    public function upsertFromTmdb(?int $tmdbId, string $name, string $type): Genre
    {
        $manifestRow = $tmdbId !== null ? $this->manifestRowForTmdbId($tmdbId) : null;
        if ($manifestRow !== null) {
            $name = $manifestRow['name'];
            $type = $manifestRow['type'] === 'both' ? $type : $manifestRow['type'];
        }

        $name = trim($name) !== '' ? trim($name) : ('genre-'.$tmdbId);
        $slug = $manifestRow['slug'] ?? $this->uniqueSlug($name, $tmdbId);

        if ($tmdbId !== null) {
            $existing = Genre::query()->where('tmdb_id', $tmdbId)->first();
            if ($existing) {
                $mergedType = $this->mergeType($existing->type, $type);
                $existing->update([
                    'name' => $name,
                    'slug' => $existing->slug ?: $slug,
                    'type' => $mergedType,
                ]);

                return $existing->fresh();
            }
        }

        return Genre::query()->updateOrCreate(
            ['slug' => $slug],
            [
                'tmdb_id' => $tmdbId,
                'name' => $name,
                'type' => $type,
            ],
        );
    }

    public function syncMovieGenres(Movie $movie, array $tmdbGenres): void
    {
        $ids = $this->resolveGenreIdsFromTmdbPayload($tmdbGenres, 'movie');
        $this->syncGenrePivot('movie_genre', 'movie_id', $movie->id, $ids);
    }

    public function syncSeriesGenres(Series $series, array $tmdbGenres): void
    {
        $ids = $this->resolveGenreIdsFromTmdbPayload($tmdbGenres, 'tv');
        $this->syncGenrePivot('series_genre', 'series_id', $series->id, $ids);
    }

    /**
     * @param  array<int, int>  $genreIds
     */
    private function syncGenrePivot(string $table, string $ownerColumn, int $ownerId, array $genreIds): void
    {
        DB::table($table)->where($ownerColumn, $ownerId)->delete();

        if ($genreIds === []) {
            return;
        }

        $rows = collect($genreIds)
            ->unique()
            ->values()
            ->map(fn (int $genreId) => [
                $ownerColumn => $ownerId,
                'genre_id' => $genreId,
            ])
            ->all();

        DB::table($table)->insert($rows);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function serializeGenres(Collection $genres): array
    {
        return $genres
            ->map(function (Genre $genre) {
                $canonical = $this->canonicalizeGenre($genre);

                return [
                    'id' => $canonical->id,
                    'tmdb_id' => $canonical->tmdb_id,
                    'name' => $canonical->name,
                    'slug' => $canonical->slug,
                    'type' => $canonical->type,
                ];
            })
            ->unique('id')
            ->values()
            ->all();
    }

    private function mergeType(string $existing, string $incoming): string
    {
        if ($existing === $incoming || $existing === 'both' || $incoming === 'both') {
            return 'both';
        }

        return 'both';
    }

    private function uniqueSlug(string $name, ?int $tmdbId = null): string
    {
        $base = Str::slug($name);
        if ($base === '' && $tmdbId !== null) {
            $base = 'genre-'.$tmdbId;
        }
        if ($base === '') {
            $base = 'genre-'.Str::lower(Str::random(6));
        }

        $slug = $base;
        $counter = 2;
        while (Genre::query()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$counter}";
            $counter++;
        }

        return $slug;
    }
}

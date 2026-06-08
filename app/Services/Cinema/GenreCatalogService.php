<?php

namespace App\Services\Cinema;

use App\Models\Genre;
use App\Models\Movie;
use App\Models\Series;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GenreCatalogService
{
    /**
     * @param  array<int, array{id?: int, name?: string}>|array<int, string>  $tmdbGenres
     * @return array<int, int>
     */
    public function resolveGenreIdsFromTmdbPayload(array $tmdbGenres, string $defaultType = 'movie'): array
    {
        $ids = [];
        $stringNames = collect($tmdbGenres)
            ->filter(fn ($genre) => is_string($genre))
            ->map(fn (string $genre) => trim($genre))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $existingByName = $stringNames === []
            ? collect()
            : Genre::query()->whereIn('name', $stringNames)->get()->keyBy('name');

        foreach ($tmdbGenres as $genre) {
            if (is_string($genre)) {
                $name = trim($genre);
                if ($name === '') {
                    continue;
                }

                $record = $existingByName->get($name);
                if ($record) {
                    $ids[] = $record->id;
                    continue;
                }

                $record = Genre::create([
                    'name' => $name,
                    'slug' => $this->uniqueSlug($name),
                    'type' => $defaultType,
                ]);
                $existingByName->put($name, $record);
                Log::warning('Genre auto-created without TMDB id', ['name' => $name, 'type' => $defaultType]);
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

            $record = $this->upsertFromTmdb($tmdbId, $name, $defaultType);
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
        if ($rawGenres === []) {
            return [];
        }

        $normalized = [];
        $tmdbIds = [];
        $names = [];

        foreach ($rawGenres as $genre) {
            if (is_string($genre)) {
                $name = trim($genre);
                if ($name !== '') {
                    $normalized[] = ['tmdb_id' => null, 'name' => $name];
                    $names[] = $name;
                }

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

            $normalized[] = ['tmdb_id' => $tmdbId, 'name' => $name !== '' ? $name : ('genre-'.$tmdbId)];
            if ($tmdbId !== null) {
                $tmdbIds[] = $tmdbId;
            }
            if ($name !== '') {
                $names[] = $name;
            }
        }

        $existing = Genre::query()
            ->when($tmdbIds !== [] || $names !== [], function ($query) use ($tmdbIds, $names) {
                $query->where(function ($inner) use ($tmdbIds, $names) {
                    if ($tmdbIds !== []) {
                        $inner->whereIn('tmdb_id', $tmdbIds);
                    }

                    if ($names !== []) {
                        $inner->orWhereIn('name', $names);
                    }
                });
            })
            ->get();

        $existingByTmdbId = $existing->filter(fn (Genre $genre) => $genre->tmdb_id !== null)->keyBy('tmdb_id');
        $existingByName = $existing->keyBy('name');

        $missingRows = [];

        foreach ($normalized as $row) {
            $tmdbId = $row['tmdb_id'];
            $name = $row['name'];

            if ($tmdbId !== null && $existingByTmdbId->has($tmdbId)) {
                continue;
            }

            if ($existingByName->has($name)) {
                continue;
            }

            $missingRows[] = [
                'tmdb_id' => $tmdbId,
                'name' => $name,
                'slug' => $this->uniqueSlug($name, $tmdbId),
                'type' => $type,
                'display_order' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if ($missingRows !== []) {
            $withTmdbId = collect($missingRows)->filter(fn (array $row) => $row['tmdb_id'] !== null)->values()->all();
            $withoutTmdbId = collect($missingRows)->filter(fn (array $row) => $row['tmdb_id'] === null)->values()->all();

            if ($withTmdbId !== []) {
                Genre::query()->upsert($withTmdbId, ['tmdb_id'], ['name', 'slug', 'type', 'updated_at']);
            }

            foreach ($withoutTmdbId as $row) {
                $record = Genre::query()->create([
                    'tmdb_id' => null,
                    'name' => $row['name'],
                    'slug' => $row['slug'],
                    'type' => $row['type'],
                    'display_order' => 0,
                ]);
                $existingByName->put($record->name, $record);
            }

            $refetched = Genre::query()
                ->where(function ($query) use ($tmdbIds, $names) {
                    if ($tmdbIds !== []) {
                        $query->whereIn('tmdb_id', $tmdbIds);
                    }

                    if ($names !== []) {
                        $query->orWhereIn('name', $names);
                    }
                })
                ->get();

            $existingByTmdbId = $refetched->filter(fn (Genre $genre) => $genre->tmdb_id !== null)->keyBy('tmdb_id');
            $existingByName = $refetched->keyBy('name');
        }

        return collect($normalized)
            ->map(function (array $row) use ($existingByTmdbId, $existingByName) {
                $tmdbId = $row['tmdb_id'];
                $name = $row['name'];
                $record = ($tmdbId !== null && $existingByTmdbId->has($tmdbId))
                    ? $existingByTmdbId->get($tmdbId)
                    : $existingByName->get($name);

                if (! $record instanceof Genre) {
                    return null;
                }

                return [
                    'id' => $record->tmdb_id,
                    'name' => $record->name,
                    'slug' => $record->slug,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    public function upsertFromTmdb(?int $tmdbId, string $name, string $type): Genre
    {
        $name = trim($name) !== '' ? trim($name) : ('genre-'.$tmdbId);
        $slug = $this->uniqueSlug($name, $tmdbId);

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
            ->map(fn (Genre $genre) => [
                'id' => $genre->id,
                'tmdb_id' => $genre->tmdb_id,
                'name' => $genre->name,
                'slug' => $genre->slug,
                'type' => $genre->type,
            ])
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

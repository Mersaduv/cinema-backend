<?php

namespace App\Services\Cinema;

use App\Models\Collection;
use App\Models\Movie;
use App\Models\User;
use App\Services\TmdbService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class CollectionService
{
    public function __construct(
        private readonly ContentImageIngestionService $contentImageIngestionService,
        private readonly TmdbService $tmdbService,
    ) {
    }

    /**
     * Fast DB-only link used during movie publication so /collections updates immediately.
     * Heavy TMDB sync and image ingestion run later in SyncMovieCollectionJob.
     *
     * @param  array<string, mixed>|null  $tmdbCollectionData
     */
    public function linkMovieQuick(?array $tmdbCollectionData, int $movieId): ?Collection
    {
        if ($tmdbCollectionData === null) {
            return null;
        }

        $tmdbId = (int) ($tmdbCollectionData['tmdb_collection_id'] ?? $tmdbCollectionData['id'] ?? 0);
        if ($tmdbId <= 0) {
            return null;
        }

        $name = trim((string) ($tmdbCollectionData['name'] ?? ''));
        if ($name === '') {
            $name = 'Collection '.$tmdbId;
        }

        $collection = Collection::query()->where('tmdb_collection_id', $tmdbId)->first();

        if (! $collection) {
            $collection = Collection::query()->create([
                'name' => $name,
                'slug' => $this->resolveUniqueSlug([$tmdbCollectionData['slug'] ?? null, $name, 'collection-'.$tmdbId]),
                'tmdb_collection_id' => $tmdbId,
                'description' => filled($tmdbCollectionData['description'] ?? null)
                    ? (string) $tmdbCollectionData['description']
                    : null,
                'poster_url' => $tmdbCollectionData['poster_url'] ?? null,
                'backdrop_url' => $tmdbCollectionData['backdrop_url'] ?? null,
                'metadata' => [
                    'tmdb' => [
                        'tmdb_collection_id' => $tmdbId,
                        'name' => $name,
                        'poster_url' => $tmdbCollectionData['poster_url'] ?? null,
                        'backdrop_url' => $tmdbCollectionData['backdrop_url'] ?? null,
                    ],
                ],
                'is_visible' => true,
            ]);
        } elseif ($name !== $collection->name) {
            $collection->update(['name' => $name]);
        }

        $this->attachMovie($collection, $movieId);

        return $collection->fresh();
    }

    /**
     * @param  array<string, mixed>|null  $tmdbCollectionData
     */
    public function syncFromTmdb(?array $tmdbCollectionData, int $movieId): void
    {
        if ($tmdbCollectionData === null) {
            return;
        }

        $tmdbId = (int) ($tmdbCollectionData['tmdb_collection_id'] ?? $tmdbCollectionData['id'] ?? 0);
        if ($tmdbId <= 0) {
            return;
        }

        $collection = $this->findOrCreateByTmdbId($tmdbId, $tmdbCollectionData);
        $this->attachMovie($collection, $movieId);
        $this->syncCollectionPartsFromTmdb($collection, $tmdbId);
    }

    /**
     * Fetch TMDB collection details and link all movies already in our DB (by tmdb_id).
     * TMDB parts order is preserved via sort_order.
     */
    public function syncCollectionPartsFromTmdb(Collection $collection, int $tmdbCollectionId): void
    {
        if ($tmdbCollectionId <= 0) {
            return;
        }

        try {
            $payload = $this->tmdbService->fetchCollection($tmdbCollectionId);
        } catch (Throwable) {
            return;
        }

        $collection = $this->refreshCollectionFromTmdbData($collection, $payload);

        $parts = $payload['parts'] ?? [];
        if (! is_array($parts) || $parts === []) {
            return;
        }

        $tmdbIds = collect($parts)
            ->pluck('tmdb_id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->values()
            ->all();

        if ($tmdbIds === []) {
            return;
        }

        $moviesByTmdbId = Movie::query()
            ->whereIn('tmdb_id', $tmdbIds)
            ->get(['id', 'tmdb_id'])
            ->keyBy('tmdb_id');

        $syncPayload = [];
        foreach ($parts as $index => $part) {
            $tmdbId = (int) ($part['tmdb_id'] ?? 0);
            $movie = $moviesByTmdbId->get($tmdbId);
            if (! $movie) {
                continue;
            }
            $syncPayload[$movie->id] = ['sort_order' => $index];
        }

        if ($syncPayload === []) {
            return;
        }

        $existing = $collection->movies()
            ->pluck('collection_movie.sort_order', 'movies.id')
            ->all();

        foreach ($existing as $movieId => $sortOrder) {
            if (! isset($syncPayload[$movieId])) {
                $syncPayload[$movieId] = ['sort_order' => (int) $sortOrder];
            }
        }

        $collection->movies()->sync($syncPayload);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function findOrCreateByTmdbId(int $tmdbId, array $data): Collection
    {
        $existing = Collection::query()->where('tmdb_collection_id', $tmdbId)->first();
        if ($existing) {
            return $this->refreshCollectionFromTmdbData($existing, $data);
        }

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            $name = 'Collection '.$tmdbId;
        }

        $collection = Collection::query()->create([
            'name' => $name,
            'slug' => $this->resolveUniqueSlug([$data['slug'] ?? null, $name, 'collection-'.$tmdbId]),
            'tmdb_collection_id' => $tmdbId,
            'description' => filled($data['description'] ?? null) ? (string) $data['description'] : null,
            'poster_url' => $data['poster_url'] ?? null,
            'backdrop_url' => $data['backdrop_url'] ?? null,
            'metadata' => [
                'tmdb' => [
                    'tmdb_collection_id' => $tmdbId,
                    'name' => $name,
                    'poster_url' => $data['poster_url'] ?? null,
                    'backdrop_url' => $data['backdrop_url'] ?? null,
                ],
            ],
            'is_visible' => true,
        ]);

        return $this->ingestCollectionImages($collection, [
            'poster_url' => $data['poster_url'] ?? null,
            'backdrop_url' => $data['backdrop_url'] ?? null,
        ]);
    }

    public function attachMovie(Collection $collection, int $movieId, ?int $sortOrder = null): void
    {
        if (! Movie::query()->whereKey($movieId)->exists()) {
            return;
        }

        if ($collection->movies()->where('movies.id', $movieId)->exists()) {
            return;
        }

        $nextOrder = $sortOrder ?? ((int) $collection->movies()->max('collection_movie.sort_order')) + 1;

        $collection->movies()->attach($movieId, [
            'sort_order' => max(0, $nextOrder),
        ]);
    }

    /**
     * @param  array<int, array{id: int, sort_order?: int}>  $movies
     */
    public function syncMovies(Collection $collection, array $movies): void
    {
        DB::transaction(function () use ($collection, $movies): void {
            $syncPayload = [];
            foreach ($movies as $index => $row) {
                $movieId = (int) ($row['id'] ?? $row['movie_id'] ?? 0);
                if ($movieId <= 0) {
                    continue;
                }
                $syncPayload[$movieId] = [
                    'sort_order' => (int) ($row['sort_order'] ?? $index),
                ];
            }

            $collection->movies()->sync($syncPayload);
        });
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function createManual(array $validated, ?User $actor = null): Collection
    {
        $name = trim((string) $validated['name']);
        $metadata = is_array($validated['metadata'] ?? null) ? $validated['metadata'] : [];

        $collection = Collection::query()->create([
            'name' => $name,
            'slug' => $this->resolveUniqueSlug([
                $validated['slug'] ?? null,
                $name,
            ]),
            'tmdb_collection_id' => isset($validated['tmdb_collection_id'])
                ? (int) $validated['tmdb_collection_id']
                : null,
            'description' => $validated['description'] ?? null,
            'poster_url' => $validated['poster_url'] ?? null,
            'backdrop_url' => $validated['backdrop_url'] ?? null,
            'metadata' => $metadata,
            'is_visible' => (bool) ($validated['is_visible'] ?? true),
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
            'created_by' => $actor?->id,
        ]);

        if (! empty($validated['movies']) && is_array($validated['movies'])) {
            $this->syncMovies($collection, $validated['movies']);
        }

        return $this->ingestCollectionImages($collection, $validated);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function updateManual(Collection $collection, array $validated): Collection
    {
        $name = trim((string) ($validated['name'] ?? $collection->name));

        $collection->fill([
            'name' => $name,
            'slug' => $this->resolveUniqueSlug([
                $validated['slug'] ?? null,
                $name,
            ], $collection->id),
            'tmdb_collection_id' => array_key_exists('tmdb_collection_id', $validated)
                ? ($validated['tmdb_collection_id'] !== null ? (int) $validated['tmdb_collection_id'] : null)
                : $collection->tmdb_collection_id,
            'description' => $validated['description'] ?? $collection->description,
            'poster_url' => $validated['poster_url'] ?? $collection->poster_url,
            'backdrop_url' => $validated['backdrop_url'] ?? $collection->backdrop_url,
            'is_visible' => array_key_exists('is_visible', $validated)
                ? (bool) $validated['is_visible']
                : $collection->is_visible,
            'sort_order' => (int) ($validated['sort_order'] ?? $collection->sort_order),
        ]);

        if (array_key_exists('metadata', $validated) && is_array($validated['metadata'])) {
            $collection->metadata = $validated['metadata'];
        }

        $collection->save();

        if (array_key_exists('movies', $validated) && is_array($validated['movies'])) {
            $this->syncMovies($collection, $validated['movies']);
        }

        return $this->ingestCollectionImages($collection, $validated);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function syncFromMoviePublication(Movie $movie, array $metadata, ?int $tmdbId = null): void
    {
        $collectionData = data_get($metadata, 'tmdb.belongs_to_collection');

        if ((! is_array($collectionData) || empty($collectionData)) && $tmdbId > 0) {
            try {
                $raw = $this->tmdbService->fetchMovieBelongsToCollection($tmdbId);
                $collectionData = $raw;
            } catch (Throwable) {
                $collectionData = null;
            }
        }

        $this->syncFromTmdb(is_array($collectionData) ? $collectionData : null, $movie->id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function refreshCollectionFromTmdbData(Collection $collection, array $data): Collection
    {
        $name = trim((string) ($data['name'] ?? $collection->name));
        $updates = [];

        if ($name !== '' && $name !== $collection->name) {
            $updates['name'] = $name;
        }

        foreach (['description', 'poster_url', 'backdrop_url'] as $field) {
            if (filled($data[$field] ?? null)) {
                $updates[$field] = $data[$field];
            }
        }

        if ($updates !== []) {
            $collection->fill($updates);
            $collection->save();
        }

        return $this->ingestCollectionImages($collection, $data);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function ingestCollectionImages(Collection $collection, array $validated): Collection
    {
        $metadata = is_array($collection->metadata) ? $collection->metadata : [];
        $result = $this->contentImageIngestionService->processCollectionImages(
            $collection,
            $validated,
            $metadata,
        );

        $collection->fill([
            'poster_url' => $result['poster_url'] ?? $collection->poster_url,
            'backdrop_url' => $result['backdrop_url'] ?? $collection->backdrop_url,
            'metadata' => $result['metadata'],
        ]);
        $collection->save();

        return $collection->fresh(['movies']);
    }

  /**
     * @param  array<int, string|null>  $candidates
     */
    private function resolveUniqueSlug(array $candidates, ?int $ignoreId = null): string
    {
        $baseSlug = '';
        foreach ($candidates as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate !== '') {
                $baseSlug = Str::slug($candidate);
                break;
            }
        }

        if ($baseSlug === '') {
            $baseSlug = 'collection-'.Str::lower(Str::random(8));
        }

        $slug = $baseSlug;
        $counter = 2;

        while (
            Collection::query()
                ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
                ->where('slug', $slug)
                ->exists()
        ) {
            $slug = "{$baseSlug}-{$counter}";
            $counter++;
        }

        return $slug;
    }
}

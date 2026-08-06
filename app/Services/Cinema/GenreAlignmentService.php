<?php

namespace App\Services\Cinema;

use App\Models\Genre;
use App\Models\Movie;
use App\Models\Series;
use App\Services\TmdbService;
use Illuminate\Support\Facades\Cache;

/**
 * Single pipeline: any admin/TMDB genre input → canonical catalog genres (DB + manifest).
 */
class GenreAlignmentService
{
    public function __construct(
        private readonly GenreCatalogService $genreCatalog,
        private readonly TmdbService $tmdbService,
        private readonly AdminReferenceOptionsService $adminReferenceOptions,
        private readonly CatalogCacheService $catalogCache,
    ) {}

    /**
     * @param  array<int, mixed>  $rawGenres  Persian/English labels, DB ids, slugs, or TMDB {id,name} rows
     * @return array{
     *     labels: array<int, string>,
     *     ids: array<int, int>,
     *     slugs: array<int, string>,
     *     refs: array<int, array{id: int, tmdb_id: int|null, name: string, slug: string}>
     * }
     */
    public function align(array $rawGenres, string $contentType = 'movie'): array
    {
        $genreType = $this->genreTypeForContent($contentType);
        $tokens = $this->normalizeRawGenreTokens($rawGenres);
        $localized = $this->localizeStringTokens($tokens, $genreType);
        $ids = $this->genreCatalog->resolveGenreIdsFromTmdbPayload($localized, $genreType);

        $records = collect($ids)
            ->map(fn (int $id) => Genre::query()->find($id))
            ->filter(fn ($genre) => $genre instanceof Genre)
            ->map(fn (Genre $genre) => $this->genreCatalog->canonicalizeGenre($genre))
            ->unique('id')
            ->values();

        return [
            'labels' => $records->pluck('name')->values()->all(),
            'ids' => $records->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            'slugs' => $records->pluck('slug')->values()->all(),
            'refs' => $records
                ->map(fn (Genre $genre) => [
                    'id' => $genre->id,
                    'tmdb_id' => $genre->tmdb_id,
                    'name' => $genre->name,
                    'slug' => $genre->slug,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<int, mixed>  $rawGenres
     * @return array{
     *     labels: array<int, string>,
     *     ids: array<int, int>,
     *     slugs: array<int, string>,
     *     refs: array<int, array{id: int, tmdb_id: int|null, name: string, slug: string}>
     * }
     */
    public function applyToMovie(Movie $movie, array $rawGenres): array
    {
        $aligned = $this->align($rawGenres, 'movie');
        $this->genreCatalog->syncMovieGenres($movie, $aligned['labels']);
        $this->afterAlignment();

        return $aligned;
    }

    /**
     * @param  array<int, mixed>  $rawGenres
     * @return array{
     *     labels: array<int, string>,
     *     ids: array<int, int>,
     *     slugs: array<int, string>,
     *     refs: array<int, array{id: int, tmdb_id: int|null, name: string, slug: string}>
     * }
     */
    public function applyToSeries(Series $series, array $rawGenres): array
    {
        $aligned = $this->align($rawGenres, 'tv');
        $this->genreCatalog->syncSeriesGenres($series, $aligned['labels']);
        $this->afterAlignment();

        return $aligned;
    }

    /**
     * @param  array<int, mixed>  $rawGenres
     * @return array<int, array{id?: int, name?: string}|string>
     */
    public function normalizeRawGenreTokens(array $rawGenres): array
    {
        $tokens = [];

        foreach ($rawGenres as $item) {
            if (is_string($item)) {
                $trimmed = trim($item);
                if ($trimmed === '') {
                    continue;
                }

                if (ctype_digit($trimmed)) {
                    $genre = Genre::query()->find((int) $trimmed);
                    $tokens[] = $genre instanceof Genre ? $genre->name : $trimmed;

                    continue;
                }

                $bySlug = Genre::query()->where('slug', $trimmed)->first();
                if ($bySlug instanceof Genre) {
                    $tokens[] = $bySlug->name;

                    continue;
                }

                $tokens[] = $trimmed;

                continue;
            }

            if (! is_array($item)) {
                continue;
            }

            $tmdbId = isset($item['tmdb_id'])
                ? (int) $item['tmdb_id']
                : (isset($item['id']) ? (int) $item['id'] : null);
            $name = trim((string) ($item['name'] ?? ''));

            if ($tmdbId !== null && $tmdbId > 0) {
                $manifestRow = $this->genreCatalog->manifestRowForTmdbId($tmdbId);
                $tokens[] = [
                    'id' => $tmdbId,
                    'name' => $manifestRow['name'] ?? $name,
                ];

                continue;
            }

            if ($name !== '') {
                $tokens[] = $name;
            }
        }

        return $tokens;
    }

    /**
     * @param  array<int, array{id?: int, name?: string}|string>  $tokens
     * @return array<int, array{id?: int, name?: string}|string>
     */
    private function localizeStringTokens(array $tokens, string $genreType): array
    {
        $strings = [];
        $stringIndexes = [];

        foreach ($tokens as $index => $token) {
            if (! is_string($token)) {
                continue;
            }

            if (preg_match('/\p{Arabic}/u', $token) === 1) {
                continue;
            }

            $stringIndexes[] = $index;
            $strings[] = $token;
        }

        if ($strings === []) {
            return $tokens;
        }

        $localized = $this->tmdbService->localizeGenreNames($strings, $genreType);
        $result = $tokens;

        foreach ($stringIndexes as $position => $index) {
            $result[$index] = $localized[$position] ?? $result[$index];
        }

        return $result;
    }

    private function genreTypeForContent(string $contentType): string
    {
        return in_array($contentType, ['tv', 'series'], true) ? 'tv' : 'movie';
    }

    /**
     * Light cache bust after genre pivot sync (no catalog rebuild / Next revalidation).
     * Callers that need a full rebuild should invoke CatalogCacheService once.
     */
    public function touchAfterAlignment(): void
    {
        Cache::forget('filter_options');
        Cache::forget('filter_options_v2');
        $this->adminReferenceOptions->invalidate();
    }

    private function afterAlignment(): void
    {
        $this->touchAfterAlignment();
        $this->catalogCache->scheduleRebuildAfterContentChange();
    }
}

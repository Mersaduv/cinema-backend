<?php

namespace App\Http\Controllers;

use App\Http\Resources\PersonBriefResource;
use App\Models\Person;
use App\Services\Cinema\GenreCatalogService;
use App\Services\TmdbService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

class TmdbController extends Controller
{
    public function __construct(
        private readonly TmdbService $tmdbService,
        private readonly GenreCatalogService $genreCatalogService,
    ) {
    }

    public function search(Request $request): JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'query' => ['required', 'string', 'max:255'],
            'language' => ['nullable', 'string', 'max:12'],
            'type' => ['nullable', Rule::in(['movie', 'tv'])],
        ])->validate();

        $type = $validated['type'] ?? 'movie';

        try {
            $results = $type === 'tv'
                ? $this->tmdbService->searchTv(
                    (string) $validated['query'],
                    $validated['language'] ?? null,
                )
                : $this->tmdbService->search(
                    (string) $validated['query'],
                    $validated['language'] ?? null,
                );
        } catch (Throwable $exception) {
            return response()->json([
                'success' => false,
                'message' => 'TMDB search failed.',
                'errors' => [
                    'tmdb' => [$exception->getMessage()],
                ],
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'results' => $results,
            ],
        ]);
    }

    public function fetch(Request $request): JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'tmdb_id' => ['required', 'integer', 'min:1'],
            'language' => ['nullable', 'string', 'max:12'],
        ])->validate();

        try {
            $movie = $this->tmdbService->fetchMovie(
                (int) $validated['tmdb_id'],
                $validated['language'] ?? null
            );
        } catch (Throwable $exception) {
            return response()->json([
                'success' => false,
                'message' => 'TMDB fetch failed.',
                'errors' => [
                    'tmdb' => [$exception->getMessage()],
                ],
            ], 422);
        }

        if (empty($movie['tmdb_id'])) {
            return response()->json([
                'success' => false,
                'message' => 'Movie not found on TMDB.',
                'errors' => ['tmdb_id' => ['Not found.']],
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'movie' => $this->enrichTmdbPayload($movie, 'movie'),
            ],
        ]);
    }

    public function fetchCollection(Request $request): JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'tmdb_id' => ['required', 'integer', 'min:1'],
            'language' => ['nullable', 'string', 'max:12'],
        ])->validate();

        try {
            $collection = $this->tmdbService->fetchCollection(
                (int) $validated['tmdb_id'],
                $validated['language'] ?? null,
            );
        } catch (Throwable $exception) {
            return response()->json([
                'success' => false,
                'message' => 'TMDB collection fetch failed.',
                'errors' => [
                    'tmdb' => [$exception->getMessage()],
                ],
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'collection' => $collection,
            ],
        ]);
    }

    public function fetchTv(Request $request): JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'tmdb_id' => ['required', 'integer', 'min:1'],
            'language' => ['nullable', 'string', 'max:12'],
            'include_seasons' => ['nullable', 'boolean'],
        ])->validate();

        $includeSeasons = $request->boolean('include_seasons', true);

        try {
            $series = $this->tmdbService->fetchTv(
                (int) $validated['tmdb_id'],
                $validated['language'] ?? null,
                $includeSeasons,
            );
        } catch (Throwable $exception) {
            return response()->json([
                'success' => false,
                'message' => 'TMDB TV fetch failed.',
                'errors' => [
                    'tmdb' => [$exception->getMessage()],
                ],
            ], 422);
        }

        if (empty($series['tmdb_id'])) {
            return response()->json([
                'success' => false,
                'message' => 'TV show not found on TMDB.',
                'errors' => ['tmdb_id' => ['Not found.']],
            ], 422);
        }

        $series = $this->enrichTmdbPayload($series, 'tv');

        if (! $includeSeasons) {
            unset($series['seasons'], $series['season_errors']);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'series' => $series,
            ],
        ]);
    }

    public function fetchTvSeason(Request $request): JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'tmdb_id' => ['required', 'integer', 'min:1'],
            'season_number' => ['required', 'integer', 'min:1'],
            'language' => ['nullable', 'string', 'max:12'],
        ])->validate();

        try {
            $season = $this->tmdbService->fetchTvSeasonForSeries(
                (int) $validated['tmdb_id'],
                (int) $validated['season_number'],
                $validated['language'] ?? null,
            );
        } catch (Throwable $exception) {
            return response()->json([
                'success' => false,
                'message' => 'TMDB TV season fetch failed.',
                'errors' => [
                    'tmdb' => [$exception->getMessage()],
                ],
            ], 422);
        }

        if (empty($season['season_number'])) {
            return response()->json([
                'success' => false,
                'message' => 'TV season not found on TMDB.',
                'errors' => ['season_number' => ['Not found.']],
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'season' => $season,
            ],
        ]);
    }

    public function fetchPerson(Request $request): JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'tmdb_person_id' => ['required', 'integer', 'min:1'],
        ])->validate();

        $id = (int) $validated['tmdb_person_id'];

        $local = Person::query()->where('tmdb_person_id', $id)->first();

        if ($local) {
            return response()->json([
                'success' => true,
                'data' => [
                    'person' => PersonBriefResource::make($local)->resolve(),
                    'source' => 'local',
                ],
            ]);
        }

        $raw = $this->tmdbService->fetchPersonFromApi($id);

        if (blank($raw['name'] ?? null)) {
            return response()->json([
                'success' => false,
                'message' => 'Person not found on TMDB.',
                'errors' => ['tmdb_person_id' => ['Not found.']],
            ], 422);
        }

        $profilePath = $raw['profile_path'] ?? null;

        return response()->json([
            'success' => true,
            'data' => [
                'person' => [
                    'tmdb_person_id' => (int) ($raw['id'] ?? $id),
                    'name' => (string) ($raw['name'] ?? ''),
                    'biography' => $raw['biography'] ?? null,
                    'birthday' => $raw['birthday'] ?? null,
                    'deathday' => $raw['deathday'] ?? null,
                    'place_of_birth' => $raw['place_of_birth'] ?? null,
                    'known_for_department' => $raw['known_for_department'] ?? null,
                    'gender' => $raw['gender'] ?? null,
                    'imdb_id' => $raw['external_ids']['imdb_id'] ?? $raw['imdb_id'] ?? null,
                    'profile_url' => $profilePath
                        ? rtrim((string) config('services.tmdb.image_base_url'), '/') . '/w500/' . ltrim((string) $profilePath, '/')
                        : null,
                ],
                'source' => 'tmdb',
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function enrichTmdbPayload(array $payload, string $type): array
    {
        $rawGenres = $payload['genres'] ?? [];
        if (! is_array($rawGenres)) {
            $rawGenres = [];
        }

        $payload['genres'] = $this->genreCatalogService->enrichGenresForTmdbPayload($rawGenres, $type);

        if (! empty($payload['production_countries']) && is_array($payload['production_countries'])) {
            $first = $payload['production_countries'][0] ?? null;
            if (is_array($first)) {
                $payload['origin_country'] = $payload['origin_country']
                    ?? ($first['iso_3166_1'] ?? $first['iso'] ?? null);
            }
        }

        $payload['original_language'] = $payload['original_language'] ?? null;
        $payload['content_rating'] = $payload['content_rating']
            ?? $payload['age_rating']
            ?? null;

        return $payload;
    }
}

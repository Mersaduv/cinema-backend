<?php

namespace App\Http\Controllers;

use App\Models\Person;
use App\Services\TmdbService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminPersonController extends Controller
{
    public function __construct(
        private readonly TmdbService $tmdbService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $search = trim((string) $request->string('search'));
        $perPage = min(max((int) $request->integer('per_page', 20), 1), 50);

        $query = Person::query()
            ->when($search !== '', fn ($q) => $q->where('name', 'like', '%' . $search . '%'))
            ->latest('updated_at');

        $paginator = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => [
                'persons' => collect($paginator->items())->map(fn (Person $p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'slug' => $p->slug,
                    'tmdb_person_id' => $p->tmdb_person_id,
                    'profile_url' => $p->getProfileImageUrl(),
                    'known_for_department' => $p->known_for_department,
                ])->values()->all(),
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                ],
            ],
        ]);
    }

    public function show(Person $person): JsonResponse
    {
        $person->loadCount([
            'movieCast as movie_cast_count',
            'seriesCast as series_cast_count',
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'person' => [
                    'id' => $person->id,
                    'name' => $person->name,
                    'slug' => $person->slug,
                    'tmdb_person_id' => $person->tmdb_person_id,
                    'imdb_id' => $person->imdb_id,
                    'biography' => $person->biography,
                    'birthday' => $person->birthday?->toDateString(),
                    'deathday' => $person->deathday?->toDateString(),
                    'place_of_birth' => $person->place_of_birth,
                    'known_for_department' => $person->known_for_department,
                    'gender' => $person->gender,
                    'profile_url' => $person->getProfileImageUrl(),
                    'stats' => [
                        'movie_cast_count' => $person->movie_cast_count,
                        'series_cast_count' => $person->series_cast_count,
                    ],
                ],
            ],
        ]);
    }

    public function refreshFromTmdb(Person $person): JsonResponse
    {
        if (!$person->tmdb_person_id) {
            return response()->json([
                'success' => false,
                'message' => 'This person has no TMDB id to refresh.',
            ], 422);
        }

        $raw = $this->tmdbService->fetchPersonFromApi((int) $person->tmdb_person_id);

        if (blank($raw['name'] ?? null)) {
            return response()->json([
                'success' => false,
                'message' => 'TMDB did not return data for this person.',
            ], 422);
        }

        $path = $raw['profile_path'] ?? null;
        $profileUrl = $path
            ? rtrim((string) config('services.tmdb.image_base_url'), '/') . '/w500/' . ltrim((string) $path, '/')
            : $person->profile_url;

        $person->update([
            'name' => (string) ($raw['name'] ?? $person->name),
            'biography' => $raw['biography'] ?? $person->biography,
            'birthday' => $raw['birthday'] ?? $person->birthday?->toDateString(),
            'deathday' => $raw['deathday'] ?? $person->deathday?->toDateString(),
            'place_of_birth' => $raw['place_of_birth'] ?? $person->place_of_birth,
            'known_for_department' => $raw['known_for_department'] ?? $person->known_for_department,
            'gender' => isset($raw['gender']) ? (int) $raw['gender'] : $person->gender,
            'profile_path_tmdb' => $path,
            'profile_url' => $profileUrl,
            'imdb_id' => $raw['external_ids']['imdb_id'] ?? $raw['imdb_id'] ?? $person->imdb_id,
            'popularity' => isset($raw['popularity']) ? round((float) $raw['popularity'], 3) : $person->popularity,
            'metadata' => array_replace($person->metadata ?? [], [
                'also_known_as' => $raw['also_known_as'] ?? [],
                'homepage' => $raw['homepage'] ?? null,
                'external_ids' => $raw['external_ids'] ?? [],
            ]),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Person profile refreshed from TMDB.',
            'data' => [
                'person' => $person->fresh(),
            ],
        ]);
    }
}

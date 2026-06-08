<?php

namespace App\Http\Controllers;

use App\Models\Movie;
use App\Models\MovieCrew;
use App\Models\Person;
use App\Models\Series;
use App\Models\SeriesCrew;
use App\Support\CatalogListResponseMapper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class PersonController extends Controller
{
    public function __construct(
        private readonly CatalogListResponseMapper $catalogListResponseMapper,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $department = trim((string) $request->string('department'));
        $search = trim((string) $request->string('search'));
        $perPage = min(max((int) $request->integer('per_page', 20), 1), 50);

        $query = Person::query()
            ->when($department !== '', fn ($q) => $q->where('known_for_department', $department))
            ->when($search !== '', fn ($q) => $q->where('name', 'like', '%' . $search . '%'))
            ->where(function ($outer) {
                $outer
                    ->whereHas(
                        'movieCast',
                        fn ($q) => $q->whereHas('movie', fn ($mq) => $mq->where('publish_status', 'published'))
                    )
                    ->orWhereHas(
                        'seriesCast',
                        fn ($q) => $q->whereHas('series', fn ($sq) => $sq->where('publish_status', 'published'))
                    );
            })
            ->withCount([
                'movieCast as movies_on_platform_count' => fn ($q) => $q->whereHas('movie', fn ($mq) => $mq->where('publish_status', 'published')),
                'seriesCast as series_on_platform_count' => fn ($q) => $q->whereHas('series', fn ($sq) => $sq->where('publish_status', 'published')),
            ])
            ->orderByDesc('popularity')
            ->orderBy('name');

        $paginator = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => [
                'persons' => collect($paginator->items())->map(fn (Person $p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'slug' => $p->slug,
                    'known_for_department' => $p->known_for_department,
                    'profile_url' => $p->getProfileImageUrl(),
                    'movies_on_platform_count' => (int) $p->movies_on_platform_count,
                    'series_on_platform_count' => (int) $p->series_on_platform_count,
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

    public function show(string $slug): JsonResponse
    {
        $payload = Cache::remember('person.public.v2.' . $slug, 3600, function () use ($slug) {
            $person = Person::query()->where('slug', $slug)->first();

            if (!$person) {
                return null;
            }

            $person->load([
                'movieCast' => fn ($q) => $q
                    ->whereHas('movie', fn ($mq) => $mq->where('publish_status', 'published'))
                    ->with(['movie' => fn ($mq) => $mq->where('publish_status', 'published')]),
                'seriesCast' => fn ($q) => $q
                    ->whereHas('series', fn ($sq) => $sq->where('publish_status', 'published'))
                    ->with(['series' => fn ($sq) => $sq->where('publish_status', 'published')]),
                'movieCrew' => fn ($q) => $q
                    ->whereHas('movie', fn ($mq) => $mq->where('publish_status', 'published'))
                    ->with(['movie' => fn ($mq) => $mq->where('publish_status', 'published')]),
                'seriesCrew' => fn ($q) => $q
                    ->whereHas('series', fn ($sq) => $sq->where('publish_status', 'published'))
                    ->with(['series' => fn ($sq) => $sq->where('publish_status', 'published')]),
            ]);

            $movieIds = $person->movieCast
                ->filter(fn ($row) => $row->movie !== null)
                ->pluck('movie.id')
                ->unique()
                ->values()
                ->all();
            $seriesIds = $person->seriesCast
                ->filter(fn ($row) => $row->series !== null)
                ->pluck('series.id')
                ->unique()
                ->values()
                ->all();

            $movieGenreMap = $this->catalogListResponseMapper->movieGenreMap($movieIds);
            $seriesGenreMap = $this->catalogListResponseMapper->seriesGenreMap($seriesIds);

            $castInMovies = $person->movieCast
                ->filter(fn ($row) => $row->movie !== null)
                ->unique(fn ($row) => $row->movie->id)
                ->map(fn ($row) => $this->catalogListResponseMapper->publicMovieListItem(
                    $this->movieCatalogRow($row->movie),
                    $movieGenreMap,
                ))
                ->sortByDesc('release_year')
                ->values()
                ->all();

            $castInSeries = $person->seriesCast
                ->filter(fn ($row) => $row->series !== null)
                ->unique(fn ($row) => $row->series->id)
                ->map(fn ($row) => $this->catalogListResponseMapper->publicSeriesListItem(
                    $this->seriesCatalogRow($row->series),
                    $seriesGenreMap,
                ))
                ->sortByDesc('release_year')
                ->values()
                ->all();

            $directing = $this->mapCrewCredits(
                $person->movieCrew->concat($person->seriesCrew),
                ['Director', 'Co-Director']
            );
            $writing = $this->mapCrewCredits(
                $person->movieCrew->concat($person->seriesCrew),
                ['Screenplay', 'Writer', 'Story', 'Novel']
            );
            $producing = $this->mapCrewCredits(
                $person->movieCrew->concat($person->seriesCrew),
                ['Producer', 'Executive Producer']
            );

            return [
                'id' => $person->id,
                'name' => $person->name,
                'slug' => $person->slug,
                'biography' => $person->biography,
                'birthday' => $person->birthday?->toDateString(),
                'deathday' => $person->deathday?->toDateString(),
                'place_of_birth' => $person->place_of_birth,
                'known_for_department' => $person->known_for_department,
                'gender' => $person->gender,
                'profile_url' => $person->getProfileImageUrl(),
                'imdb_id' => $person->imdb_id,
                'stats' => [
                    'movies_count' => count($castInMovies),
                    'series_count' => count($castInSeries),
                    'movies_on_platform' => count($castInMovies),
                    'series_on_platform' => count($castInSeries),
                ],
                'cast_in_movies' => $castInMovies,
                'cast_in_series' => $castInSeries,
                'directing' => $directing,
                'writing' => $writing,
                'producing' => $producing,
            ];
        });

        if ($payload === null) {
            return response()->json([
                'success' => false,
                'message' => 'Person not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'person' => $payload,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function movieCatalogRow(Movie $movie): array
    {
        return [
            'id' => $movie->id,
            'title' => $movie->title,
            'slug' => $movie->slug,
            'synopsis' => $movie->synopsis,
            'release_year' => $movie->release_year,
            'metadata' => $movie->metadata,
            'poster_path' => $movie->poster_path,
            'has_subtitle' => $movie->has_subtitle,
            'has_dubbed_audio' => $movie->has_dubbed_audio,
            'filter_rating' => $movie->filter_rating,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function seriesCatalogRow(Series $series): array
    {
        return [
            'id' => $series->id,
            'title' => $series->title,
            'slug' => $series->slug,
            'original_title' => $series->original_title,
            'synopsis' => $series->synopsis,
            'release_year' => $series->release_year,
            'status' => $series->status,
            'poster_url' => $series->poster_url,
            'backdrop_url' => $series->backdrop_url,
            'network' => $series->network,
            'number_of_seasons' => $series->number_of_seasons,
            'number_of_episodes' => $series->number_of_episodes,
            'metadata' => $series->metadata,
            'has_subtitle' => $series->has_subtitle,
            'has_dubbed' => $series->has_dubbed,
            'filter_rating' => $series->filter_rating,
        ];
    }

    private function moviePosterPublic(Movie $movie): ?string
    {
        $metadata = is_array($movie->metadata) ? $movie->metadata : [];
        if ($movie->poster_path && preg_match('#^https?://#i', $movie->poster_path) === 1) {
            return $movie->poster_path;
        }

        return data_get($metadata, 'tmdb.poster_url');
    }

    /**
     * @param  \Illuminate\Support\Collection<int, MovieCrew|SeriesCrew>  $rows
     * @param  array<int, string>  $jobs
     * @return array<int, mixed>
     */
    private function mapCrewCredits(\Illuminate\Support\Collection $rows, array $jobs): array
    {
        return $rows
            ->filter(function ($row) use ($jobs) {
                $job = trim((string) ($row->job ?? ''));

                if (!in_array($job, $jobs, true)) {
                    return false;
                }

                if ($row instanceof MovieCrew) {
                    return $row->movie !== null;
                }

                if ($row instanceof SeriesCrew) {
                    return $row->series !== null;
                }

                return false;
            })
            ->map(function ($row) {
                if ($row instanceof MovieCrew) {
                    return [
                        'kind' => 'movie',
                        'title' => $row->movie->title,
                        'slug' => $row->movie->slug,
                        'release_year' => $row->movie->release_year,
                        'job' => $row->job,
                        'poster_url' => $this->moviePosterPublic($row->movie),
                    ];
                }

                return [
                    'kind' => 'series',
                    'title' => $row->series->title,
                    'slug' => $row->series->slug,
                    'release_year' => $row->series->release_year,
                    'job' => $row->job,
                    'poster_url' => $row->series->poster_url,
                ];
            })
            ->sortByDesc('release_year')
            ->values()
            ->all();
    }
}

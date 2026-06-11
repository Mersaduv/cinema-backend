<?php

namespace App\Services\Cinema;

use App\Models\Movie;
use App\Models\MovieCast;
use App\Models\MovieCrew;
use App\Models\Person;
use App\Models\Series;
use App\Models\SeriesCast;
use App\Models\SeriesCrew;
use App\Jobs\ProcessPersonImagesJob;
use App\Services\TmdbService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PersonIngestionService
{
    private int $newPersonApiCallsThisSync = 0;

    /** @var array<int, Person> */
    private array $preloadedPeopleByTmdbId = [];

    /** @var array<int, true> */
    private array $pendingBioEnrichTmdbIds = [];

    public function __construct(
        private readonly TmdbService $tmdbService,
        private readonly ContentImageIngestionService $contentImageIngestionService,
    ) {
    }

    /**
     * @param  array<string, mixed>  $tmdbPersonData
     */
    public function upsertPersonFromTmdbData(array $tmdbPersonData): Person
    {
        $rawId = $tmdbPersonData['id'] ?? $tmdbPersonData['tmdb_person_id'] ?? null;
        $tmdbId = is_numeric($rawId) ? (int) $rawId : null;
        $name = trim((string) ($tmdbPersonData['name'] ?? ''));

        if ($name === '') {
            $name = 'Unknown';
        }

        if (!$tmdbId || $tmdbId <= 0) {
            return $this->upsertManualPersonFromCastData($tmdbPersonData, $name);
        }

        $existing = $this->preloadedPeopleByTmdbId[$tmdbId]
            ?? Person::query()->where('tmdb_person_id', $tmdbId)->first();

        if ($existing === null && $this->canCreatePersonFromPayloadWithoutApi($tmdbPersonData)) {
            return $this->upsertPersonFromPayloadWithoutApi($tmdbId, $tmdbPersonData, $name);
        }

        if ($existing) {
            $updates = $this->buildPersonProfileUpdatesFromPayload($existing, $tmdbPersonData);

            if (isset($tmdbPersonData['popularity'])) {
                $updates['popularity'] = round((float) $tmdbPersonData['popularity'], 3);
            }

            if ($updates !== []) {
                $existing->update($updates);
            }

            $existing = $existing->fresh();
            $this->markPersonForBioEnrich($existing);
            $this->queueProfileImageIngestIfNeeded(
                $existing,
                $updates['profile_url'] ?? $existing->profile_url,
            );

            return $existing;
        }

        if ($this->newPersonApiCallsThisSync > 15) {
            usleep(50_000);
        }
        $this->newPersonApiCallsThisSync++;

        $api = $this->tmdbService->fetchPersonFromApi($tmdbId);
        $resolvedName = trim((string) ($api['name'] ?? '')) ?: $name;
        $slug = $this->allocateUniqueSlug(Str::slug($resolvedName) ?: 'person');

        $profilePath = isset($api['profile_path']) ? (string) $api['profile_path'] : (isset($tmdbPersonData['profile_path']) ? (string) $tmdbPersonData['profile_path'] : null);
        $profileUrl = $this->resolveProfileUrlFromPayload($tmdbPersonData, $profilePath);

        $imdb = $api['external_ids']['imdb_id'] ?? $api['imdb_id'] ?? null;

        $metadata = [
            'also_known_as' => $api['also_known_as'] ?? [],
            'homepage' => $api['homepage'] ?? null,
            'external_ids' => $api['external_ids'] ?? [],
        ];
        $profileImage = $tmdbPersonData['profile_image'] ?? null;

        if (is_array($profileImage) && filled($profileImage['original'] ?? null)) {
            $metadata['images']['profile'] = $profileImage;
        }

        $person = Person::query()->create([
            'tmdb_person_id' => $tmdbId,
            'imdb_id' => filled($imdb) ? (string) $imdb : null,
            'name' => $resolvedName,
            'slug' => $slug,
            'biography' => filled($api['biography'] ?? null) ? (string) $api['biography'] : null,
            'birthday' => $api['birthday'] ?? null,
            'deathday' => $api['deathday'] ?? null,
            'place_of_birth' => filled($api['place_of_birth'] ?? null) ? (string) $api['place_of_birth'] : null,
            'known_for_department' => filled($api['known_for_department'] ?? null) ? (string) $api['known_for_department'] : null,
            'gender' => isset($api['gender']) ? (int) $api['gender'] : null,
            'popularity' => isset($api['popularity']) ? round((float) $api['popularity'], 3) : (isset($tmdbPersonData['popularity']) ? round((float) $tmdbPersonData['popularity'], 3) : null),
            'profile_path_tmdb' => $profilePath,
            'profile_url' => $profileUrl,
            'metadata' => $metadata,
        ]);

        $this->queueProfileImageIngestIfNeeded($person, $profileUrl);

        return $person;
    }

    public function enrichPersonProfileFromTmdbIfNeeded(Person $person): Person
    {
        $tmdbId = (int) ($person->tmdb_person_id ?? 0);

        if ($tmdbId <= 0 || filled($person->biography)) {
            return $person;
        }

        if ($this->newPersonApiCallsThisSync > 15) {
            usleep(50_000);
        }
        $this->newPersonApiCallsThisSync++;

        try {
            $api = $this->tmdbService->fetchPersonFromApi($tmdbId);
        } catch (\Throwable $exception) {
            Log::warning('PersonIngestionService: TMDB profile enrich failed', [
                'person_id' => $person->id,
                'tmdb_person_id' => $tmdbId,
                'error' => $exception->getMessage(),
            ]);

            return $person;
        }

        $this->applyPersonApiProfile($person, $api);

        return $person->fresh();
    }

    /**
     * @param  array<string, mixed>  $api
     */
    private function applyPersonApiProfile(Person $person, array $api): void
    {
        if (blank($api['name'] ?? null) && blank($api['biography'] ?? null)) {
            return;
        }

        $path = isset($api['profile_path']) ? (string) $api['profile_path'] : null;
        $profileUrl = $path
            ? $this->tmdbProfileUrl($path)
            : ($person->profile_url ?: null);
        $imdb = $api['external_ids']['imdb_id'] ?? $api['imdb_id'] ?? null;

        $person->update([
            'name' => filled($api['name'] ?? null) ? (string) $api['name'] : $person->name,
            'biography' => filled($api['biography'] ?? null) ? (string) $api['biography'] : $person->biography,
            'birthday' => $api['birthday'] ?? $person->birthday?->toDateString(),
            'deathday' => $api['deathday'] ?? $person->deathday?->toDateString(),
            'place_of_birth' => filled($api['place_of_birth'] ?? null)
                ? (string) $api['place_of_birth']
                : $person->place_of_birth,
            'known_for_department' => filled($api['known_for_department'] ?? null)
                ? (string) $api['known_for_department']
                : $person->known_for_department,
            'gender' => isset($api['gender']) ? (int) $api['gender'] : $person->gender,
            'popularity' => isset($api['popularity'])
                ? round((float) $api['popularity'], 3)
                : $person->popularity,
            'profile_path_tmdb' => $path ?: $person->profile_path_tmdb,
            'profile_url' => $profileUrl,
            'imdb_id' => filled($imdb) ? (string) $imdb : $person->imdb_id,
            'metadata' => array_replace($person->metadata ?? [], [
                'also_known_as' => $api['also_known_as'] ?? [],
                'homepage' => $api['homepage'] ?? null,
                'external_ids' => $api['external_ids'] ?? [],
            ]),
        ]);

        if (filled($api['biography'] ?? null) && filled($person->slug)) {
            Cache::forget('person.public.v3.' . $person->slug);
        }

        $this->queueProfileImageIngestIfNeeded($person->fresh(), $profileUrl);
    }

    private function queueProfileImageIngestIfNeeded(Person $person, ?string $sourceUrl = null): void
    {
        $metadata = is_array($person->metadata) ? $person->metadata : [];

        if (filled(data_get($metadata, 'images.profile.original'))) {
            return;
        }

        $source = $sourceUrl ?: $person->profile_url;

        if (! filled($source)) {
            return;
        }

        ProcessPersonImagesJob::dispatch($person->id, $source)->afterResponse();
    }

    private function markPersonForBioEnrich(Person $person): void
    {
        $tmdbId = (int) ($person->tmdb_person_id ?? 0);

        if ($tmdbId <= 0 || filled($person->biography)) {
            return;
        }

        $this->pendingBioEnrichTmdbIds[$tmdbId] = true;
    }

    private function flushPendingBioEnrichments(): void
    {
        if ($this->pendingBioEnrichTmdbIds === []) {
            return;
        }

        $tmdbIds = array_keys($this->pendingBioEnrichTmdbIds);
        $this->pendingBioEnrichTmdbIds = [];

        try {
            $apiByTmdbId = $this->tmdbService->fetchPersonsFromApiParallel($tmdbIds);
        } catch (\Throwable $exception) {
            Log::warning('PersonIngestionService: batch TMDB profile enrich failed', [
                'tmdb_person_ids' => $tmdbIds,
                'error' => $exception->getMessage(),
            ]);

            return;
        }

        $people = Person::query()
            ->whereIn('tmdb_person_id', $tmdbIds)
            ->where(function ($query) {
                $query->whereNull('biography')->orWhere('biography', '');
            })
            ->get()
            ->keyBy('tmdb_person_id');

        foreach ($tmdbIds as $tmdbId) {
            $person = $people->get($tmdbId);

            if (!$person) {
                continue;
            }

            $this->applyPersonApiProfile($person, $apiByTmdbId[$tmdbId] ?? []);
        }
    }

    /**
     * @param  array<string, mixed>  $tmdbCreditsData
     */
    public function syncMovieCredits(Movie $movie, array $tmdbCreditsData): void
    {
        $this->newPersonApiCallsThisSync = 0;
        $this->preloadedPeopleByTmdbId = [];
        $this->pendingBioEnrichTmdbIds = [];

        try {
            DB::transaction(function () use ($movie, $tmdbCreditsData) {
                MovieCast::query()->where('movie_id', $movie->id)->delete();
                MovieCrew::query()->where('movie_id', $movie->id)->delete();

                $castList = collect($tmdbCreditsData['cast'] ?? [])
                    ->filter(fn ($row) => is_array($row))
                    ->sortBy(fn (array $row) => $row['order'] ?? 999)
                    ->take(20)
                    ->values();

                $crewList = collect($tmdbCreditsData['crew'] ?? [])
                    ->filter(fn ($row) => is_array($row) && $this->movieCrewShouldSync($row))
                    ->values();

                $this->preloadPeopleForCreditRows($castList->all(), $crewList->all());

                $castRows = [];
                $now = now();

                foreach ($castList as $castItem) {
                    try {
                        $person = $this->upsertPersonFromTmdbData($this->normalizeIncomingCastRow($castItem));
                    } catch (\Throwable $e) {
                        Log::warning('PersonIngestionService: movie cast person failed', [
                            'movie_id' => $movie->id,
                            'error' => $e->getMessage(),
                        ]);

                        continue;
                    }

                    $castRows[] = [
                        'movie_id' => $movie->id,
                        'person_id' => $person->id,
                        'tmdb_credit_id' => $this->nullableString($castItem['credit_id'] ?? null),
                        'character_name' => $this->nullableString($castItem['character'] ?? $castItem['character_name'] ?? null),
                        'cast_order' => (int) ($castItem['order'] ?? 0),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                $crewRows = [];

                foreach ($crewList as $crewItem) {
                    try {
                        $person = $this->upsertPersonFromTmdbData($this->normalizeIncomingCrewRow($crewItem));
                    } catch (\Throwable $e) {
                        Log::warning('PersonIngestionService: movie crew person failed', [
                            'movie_id' => $movie->id,
                            'error' => $e->getMessage(),
                        ]);

                        continue;
                    }

                    $crewRows[] = [
                        'movie_id' => $movie->id,
                        'person_id' => $person->id,
                        'tmdb_credit_id' => $this->nullableString($crewItem['credit_id'] ?? null),
                        'job' => $this->nullableString($crewItem['job'] ?? null),
                        'department' => $this->nullableString($crewItem['department'] ?? null),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($castRows !== []) {
                    DB::table('movie_cast')->insert($castRows);
                }

                if ($crewRows !== []) {
                    DB::table('movie_crew')->insert($crewRows);
                }

                $snapshot = MovieCast::query()
                    ->where('movie_id', $movie->id)
                    ->with('person')
                    ->orderBy('cast_order')
                    ->take(10)
                    ->get()
                    ->map(fn (MovieCast $row) => [
                        'name' => $row->person?->name,
                        'character' => $row->character_name,
                        'profile_url' => $row->person?->getProfileImageUrl(),
                        'tmdb_person_id' => $row->person?->tmdb_person_id,
                    ])
                    ->values()
                    ->all();

                $movie->update(['cast_members' => $snapshot]);
            });
        } catch (\Throwable $e) {
            Log::warning('PersonIngestionService: syncMovieCredits transaction failed', [
                'movie_id' => $movie->id,
                'error' => $e->getMessage(),
            ]);
        }

        $this->flushPendingBioEnrichments();
    }

    /**
     * @param  array<string, mixed>  $tmdbAggregateCreditsData
     */
    public function syncSeriesCredits(Series $series, array $tmdbAggregateCreditsData): void
    {
        $this->newPersonApiCallsThisSync = 0;
        $this->preloadedPeopleByTmdbId = [];
        $this->pendingBioEnrichTmdbIds = [];

        try {
            DB::transaction(function () use ($series, $tmdbAggregateCreditsData) {
                SeriesCast::query()->where('series_id', $series->id)->delete();
                SeriesCrew::query()->where('series_id', $series->id)->delete();

                $castList = collect($tmdbAggregateCreditsData['cast'] ?? [])
                    ->sortBy(fn (array $row) => $row['order'] ?? 999)
                    ->take(20)
                    ->values();

                $crewList = collect($tmdbAggregateCreditsData['crew'] ?? [])
                    ->filter(fn ($row) => is_array($row) && $this->movieCrewShouldSync($row));

                $this->preloadPeopleForCreditRows($castList->all(), $crewList->all());

                $castRows = [];
                $crewRows = [];
                $now = now();

                foreach ($castList as $castItem) {
                    if (!is_array($castItem)) {
                        continue;
                    }

                    $character = $this->resolveAggregateCharacter($castItem);

                    try {
                        $person = $this->upsertPersonFromTmdbData($this->normalizeIncomingCastRow($castItem, $character));
                    } catch (\Throwable $e) {
                        Log::warning('PersonIngestionService: series cast person failed', [
                            'series_id' => $series->id,
                            'error' => $e->getMessage(),
                        ]);

                        continue;
                    }

                    $castRows[] = [
                        'series_id' => $series->id,
                        'person_id' => $person->id,
                        'tmdb_credit_id' => $this->nullableString($castItem['credit_id'] ?? null),
                        'character_name' => $character,
                        'episode_count' => (int) ($castItem['episode_count'] ?? $castItem['total_episode_count'] ?? 0),
                        'cast_order' => (int) ($castItem['order'] ?? 0),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                foreach ($crewList as $crewItem) {
                    if (!is_array($crewItem)) {
                        continue;
                    }

                    try {
                        $person = $this->upsertPersonFromTmdbData($this->normalizeIncomingCrewRow($crewItem));
                    } catch (\Throwable $e) {
                        Log::warning('PersonIngestionService: series crew person failed', [
                            'series_id' => $series->id,
                            'error' => $e->getMessage(),
                        ]);

                        continue;
                    }

                    $crewRows[] = [
                        'series_id' => $series->id,
                        'person_id' => $person->id,
                        'tmdb_credit_id' => $this->nullableString($crewItem['credit_id'] ?? null),
                        'job' => $this->nullableString($crewItem['job'] ?? null),
                        'department' => $this->nullableString($crewItem['department'] ?? null),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($castRows !== []) {
                    DB::table('series_cast')->insert($castRows);
                }

                if ($crewRows !== []) {
                    DB::table('series_crew')->insert($crewRows);
                }
            });
        } catch (\Throwable $e) {
            Log::warning('PersonIngestionService: syncSeriesCredits transaction failed', [
                'series_id' => $series->id,
                'error' => $e->getMessage(),
            ]);
        }

        $this->flushPendingBioEnrichments();
    }

    /**
     * @param  array<string, mixed>  $tmdbPersonData
     */
    private function upsertManualPersonFromCastData(array $tmdbPersonData, string $name): Person
    {
        $slug = $this->allocateUniqueSlug(Str::slug($name) ?: 'person');
        $profileUrl = $this->resolveProfileUrlFromPayload($tmdbPersonData, null);
        $metadata = ['manual' => true];
        $profileImage = $tmdbPersonData['profile_image'] ?? null;

        if (is_array($profileImage) && filled($profileImage['original'] ?? null)) {
            $metadata['images']['profile'] = $profileImage;
        }

        $person = Person::query()->create([
            'tmdb_person_id' => null,
            'imdb_id' => null,
            'name' => $name,
            'slug' => $slug,
            'biography' => null,
            'birthday' => null,
            'deathday' => null,
            'place_of_birth' => null,
            'known_for_department' => $this->nullableString($tmdbPersonData['known_for_department'] ?? null) ?? 'Acting',
            'gender' => null,
            'popularity' => null,
            'profile_path_tmdb' => null,
            'profile_url' => $profileUrl,
            'metadata' => $metadata,
        ]);

        $this->queueProfileImageIngestIfNeeded($person, $profileUrl);

        return $person;
    }

    private function allocateUniqueSlug(string $base): string
    {
        $slug = $base;
        $counter = 2;

        while (Person::query()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$counter}";
            $counter++;
        }

        return $slug;
    }

    private function tmdbProfileUrl(string $profilePath): string
    {
        return rtrim((string) config('services.tmdb.image_base_url'), '/') . '/w500/' . ltrim($profilePath, '/');
    }

    /**
     * @param  array<string, mixed>  $tmdbPersonData
     * @return array<string, mixed>
     */
    private function buildPersonProfileUpdatesFromPayload(Person $person, array $tmdbPersonData): array
    {
        $updates = [];
        $managedProfileUrl = $this->managedProfileUrlFromPayload($tmdbPersonData);

        if ($managedProfileUrl !== null) {
            $updates['profile_url'] = $managedProfileUrl;

            $profileImage = $tmdbPersonData['profile_image'] ?? null;

            if (is_array($profileImage) && filled($profileImage['original'] ?? null)) {
                $metadata = is_array($person->metadata) ? $person->metadata : [];
                $metadata['images']['profile'] = $profileImage;
                $updates['metadata'] = $metadata;
            }

            return $updates;
        }

        $path = isset($tmdbPersonData['profile_path']) ? (string) $tmdbPersonData['profile_path'] : null;

        if ($path !== null && $path !== '' && $path !== $person->profile_path_tmdb) {
            $updates['profile_path_tmdb'] = $path;
            $updates['profile_url'] = $this->tmdbProfileUrl($path);
        }

        return $updates;
    }

    /**
     * @param  array<string, mixed>  $tmdbPersonData
     */
    private function resolveProfileUrlFromPayload(array $tmdbPersonData, ?string $profilePath): ?string
    {
        $managedProfileUrl = $this->managedProfileUrlFromPayload($tmdbPersonData);

        if ($managedProfileUrl !== null) {
            return $managedProfileUrl;
        }

        if (filled($profilePath)) {
            return $this->tmdbProfileUrl((string) $profilePath);
        }

        return $this->nullableString($tmdbPersonData['profile_url'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $tmdbPersonData
     */
    private function managedProfileUrlFromPayload(array $tmdbPersonData): ?string
    {
        $profileUrl = $this->nullableString($tmdbPersonData['profile_url'] ?? null);

        if ($profileUrl !== null && $this->isManagedPublicImageUrl($profileUrl)) {
            return $profileUrl;
        }

        $profileImage = $tmdbPersonData['profile_image'] ?? null;
        $original = is_array($profileImage) ? $this->nullableString($profileImage['original'] ?? null) : null;

        if ($original !== null && $this->isManagedPublicImageUrl($original)) {
            return $original;
        }

        return null;
    }

    private function isManagedPublicImageUrl(string $url): bool
    {
        $prefix = '/' . trim((string) config('cinema_images.public_prefix', '/images'), '/');

        return str_starts_with($url, $prefix . '/') || $url === $prefix;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function normalizeIncomingCastRow(array $item, ?string $characterOverride = null): array
    {
        $id = $item['tmdb_person_id'] ?? $item['id'] ?? null;
        $character = $characterOverride ?? ($item['character'] ?? $item['character_name'] ?? '');

        return [
            'id' => $id,
            'name' => $item['name'] ?? '',
            'character' => $character,
            'profile_path' => $item['profile_path'] ?? null,
            'profile_url' => $item['profile_url'] ?? null,
            'profile_image' => is_array($item['profile_image'] ?? null) ? $item['profile_image'] : null,
            'profile_source_url' => $item['profile_source_url'] ?? null,
            'credit_id' => $item['credit_id'] ?? null,
            'order' => $item['order'] ?? 0,
            'known_for_department' => $item['known_for_department'] ?? 'Acting',
            'popularity' => $item['popularity'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function normalizeIncomingCrewRow(array $item): array
    {
        $id = $item['tmdb_person_id'] ?? $item['id'] ?? null;

        return [
            'id' => $id,
            'name' => $item['name'] ?? '',
            'job' => $item['job'] ?? '',
            'department' => $item['department'] ?? '',
            'profile_path' => $item['profile_path'] ?? null,
            'profile_url' => $item['profile_url'] ?? null,
            'credit_id' => $item['credit_id'] ?? null,
            'known_for_department' => $item['known_for_department'] ?? null,
            'popularity' => $item['popularity'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $castItem
     */
    private function resolveAggregateCharacter(array $castItem): ?string
    {
        if (filled($castItem['character'] ?? null)) {
            return $this->nullableString($castItem['character']);
        }

        $roles = collect($castItem['roles'] ?? [])->filter(fn ($r) => is_array($r));

        $first = $roles->pluck('character')->filter()->first();

        return $first ? (string) $first : null;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function movieCrewShouldSync(array $item): bool
    {
        $job = trim((string) ($item['job'] ?? ''));
        $department = trim((string) ($item['department'] ?? ''));

        if ($job === 'Original Music Composer') {
            return true;
        }

        return match ($department) {
            'Directing' => in_array($job, ['Director', 'Co-Director'], true),
            'Writing' => in_array($job, ['Screenplay', 'Writer', 'Story', 'Novel'], true),
            'Production' => in_array($job, ['Producer', 'Executive Producer'], true),
            'Camera' => $job === 'Director of Photography',
            'Music' => $job === 'Original Music Composer',
            default => false,
        };
    }

    private function nullableString(mixed $value): ?string
    {
        $s = trim((string) ($value ?? ''));

        return $s !== '' ? $s : null;
    }

    /**
     * @param  array<string, mixed>  $tmdbPersonData
     */
    /**
     * @param  array<int, array<string, mixed>>  $castRows
     * @param  array<int, array<string, mixed>>  $crewRows
     */
    private function preloadPeopleForCreditRows(array $castRows, array $crewRows): void
    {
        $tmdbIds = collect($castRows)
            ->merge($crewRows)
            ->map(function (array $row): ?int {
                $rawId = $row['tmdb_person_id'] ?? $row['id'] ?? null;

                return is_numeric($rawId) ? (int) $rawId : null;
            })
            ->filter(fn (?int $id) => $id !== null && $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($tmdbIds === []) {
            return;
        }

        $this->preloadedPeopleByTmdbId = Person::query()
            ->whereIn('tmdb_person_id', $tmdbIds)
            ->get()
            ->keyBy('tmdb_person_id')
            ->all();
    }

    /**
     * @param  array<string, mixed>  $tmdbPersonData
     */
    private function canCreatePersonFromPayloadWithoutApi(array $tmdbPersonData): bool
    {
        $profileUrl = $this->nullableString($tmdbPersonData['profile_url'] ?? null);
        $profilePath = $this->nullableString($tmdbPersonData['profile_path'] ?? null);

        return $profileUrl !== null || $profilePath !== null;
    }

    /**
     * @param  array<string, mixed>  $tmdbPersonData
     */
    private function upsertPersonFromPayloadWithoutApi(int $tmdbId, array $tmdbPersonData, string $name): Person
    {
        $resolvedName = trim((string) ($tmdbPersonData['name'] ?? '')) ?: $name;
        $slug = $this->allocateUniqueSlug(Str::slug($resolvedName) ?: 'person');
        $profilePath = $this->nullableString($tmdbPersonData['profile_path'] ?? null);
        $profileUrl = $this->resolveProfileUrlFromPayload($tmdbPersonData, $profilePath);
        $metadata = ['ingested_from' => 'series_payload'];
        $profileImage = $tmdbPersonData['profile_image'] ?? null;

        if (is_array($profileImage) && filled($profileImage['original'] ?? null)) {
            $metadata['images']['profile'] = $profileImage;
        }

        $person = Person::query()->create([
            'tmdb_person_id' => $tmdbId,
            'imdb_id' => null,
            'name' => $resolvedName,
            'slug' => $slug,
            'biography' => null,
            'birthday' => null,
            'deathday' => null,
            'place_of_birth' => null,
            'known_for_department' => $this->nullableString($tmdbPersonData['known_for_department'] ?? null) ?? 'Acting',
            'gender' => null,
            'popularity' => isset($tmdbPersonData['popularity']) ? round((float) $tmdbPersonData['popularity'], 3) : null,
            'profile_path_tmdb' => $profilePath,
            'profile_url' => $profileUrl,
            'metadata' => $metadata,
        ]);

        $this->markPersonForBioEnrich($person);
        $this->queueProfileImageIngestIfNeeded($person, $profileUrl);

        return $person;
    }
}

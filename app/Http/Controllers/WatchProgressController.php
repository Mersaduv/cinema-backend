<?php

namespace App\Http\Controllers;

use App\Models\Movie;
use App\Models\Series;
use App\Models\SeriesEpisode;
use App\Models\User;
use App\Models\WatchProgress;
use App\Services\Cinema\WatchProgressLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class WatchProgressController extends Controller
{
    public function __construct(
        private readonly WatchProgressLifecycleService $watchProgressLifecycle,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 401);
        }

        $validated = Validator::make($request->all(), [
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'include_completed' => ['sometimes', 'boolean'],
            'media_type' => ['sometimes', Rule::in(['movie', 'series'])],
        ])->validate();

        $limit = (int) ($validated['limit'] ?? 20);
        $includeCompleted = (bool) ($validated['include_completed'] ?? false);
        $mediaType = $validated['media_type'] ?? null;

        $this->watchProgressLifecycle->purgeOrphans();
        $this->watchProgressLifecycle->purgeUnplayable();

        $query = WatchProgress::query()
            ->where('user_id', $user->id)
            ->playable()
            ->orderByDesc('last_watched_at');

        if (! $includeCompleted) {
            $query->where('is_completed', false);
        }

        if (is_string($mediaType) && $mediaType !== '') {
            $query->where('media_type', $mediaType);
        }

        $items = $query->limit($limit)->get()->map(fn (WatchProgress $row) => $this->transform($row));

        return response()->json([
            'success' => true,
            'data' => ['items' => $items],
        ]);
    }

    public function show(Request $request, string $mediaId): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 401);
        }

        $episodeId = $request->query('episodeId');

        $row = WatchProgress::query()
            ->where('user_id', $user->id)
            ->playable()
            ->forMedia($mediaId, is_string($episodeId) ? $episodeId : null)
            ->first();

        if (! $row) {
            return response()->json([
                'success' => true,
                'data' => ['progress' => null],
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => ['progress' => $this->transform($row)],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 401);
        }

        $validated = $this->validatePayload($request);

        $mediaType = $validated['episodeId'] ? 'series' : 'movie';
        $mediaId = (int) $validated['mediaId'];
        $episodeId = $validated['episodeId'] ? (int) $validated['episodeId'] : null;

        if (! $this->watchProgressLifecycle->assertMediaIsTrackable($mediaType, $mediaId, $episodeId)) {
            return response()->json([
                'success' => false,
                'message' => 'Watch progress cannot be stored for unavailable content.',
            ], 410);
        }

        $episodeKey = $validated['episodeId']
            ? 'ep_'.$validated['episodeId']
            : 'movie';

        $percent = $this->normalizePercent($validated['percentWatched'], $validated['currentTime'], $validated['duration']);
        $isCompleted = (bool) ($validated['isCompleted'] ?? ($percent >= 90));

        $row = WatchProgress::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'media_id' => (int) $validated['mediaId'],
                'episode_key' => $episodeKey,
            ],
            [
                'media_type' => $validated['episodeId'] ? 'series' : 'movie',
                'episode_id' => $validated['episodeId'] ? (int) $validated['episodeId'] : null,
                'season_number' => $validated['seasonNumber'] ?? null,
                'episode_number' => $validated['episodeNumber'] ?? null,
                'current_time' => (float) $validated['currentTime'],
                'duration' => (int) round((float) $validated['duration']),
                'percent_watched' => $percent,
                'is_completed' => $isCompleted,
                'platform' => $validated['platform'] ?? 'WEB',
                'device_id' => $validated['deviceId'] ?? $request->header('X-Device-Id'),
                'last_watched_at' => Carbon::parse($validated['lastWatchedAt']),
            ],
        );

        $syncedAt = now()->toIso8601String();

        return response()->json([
            'success' => true,
            'syncedAt' => $syncedAt,
            'serverTime' => (float) $row->current_time,
            'data' => ['progress' => $this->transform($row)],
        ]);
    }

    public function migrate(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 401);
        }

        $validated = Validator::make($request->all(), [
            'records' => ['required', 'array', 'min:1', 'max:50'],
            'records.*.mediaId' => ['required', 'integer', 'min:1'],
            'records.*.episodeId' => ['nullable', 'string'],
            'records.*.currentTime' => ['required', 'numeric', 'min:0'],
            'records.*.duration' => ['required', 'numeric', 'min:0'],
            'records.*.percentWatched' => ['nullable', 'numeric', 'min:0'],
            'records.*.isCompleted' => ['nullable', 'boolean'],
            'records.*.seasonNumber' => ['nullable', 'integer', 'min:1'],
            'records.*.episodeNumber' => ['nullable', 'integer', 'min:1'],
            'records.*.platform' => ['nullable', Rule::in(['WEB', 'MOBILE', 'TV'])],
            'records.*.deviceId' => ['nullable', 'string', 'max:64'],
            'records.*.lastWatchedAt' => ['required', 'date'],
        ])->validate();

        $migrated = [];

        foreach ($validated['records'] as $record) {
            $mediaType = ! empty($record['episodeId']) ? 'series' : 'movie';
            $mediaId = (int) $record['mediaId'];
            $episodeId = ! empty($record['episodeId']) ? (int) $record['episodeId'] : null;

            if (! $this->watchProgressLifecycle->assertMediaIsTrackable($mediaType, $mediaId, $episodeId)) {
                continue;
            }

            $episodeKey = ! empty($record['episodeId'])
                ? 'ep_'.$record['episodeId']
                : 'movie';

            $percent = $this->normalizePercent(
                $record['percentWatched'] ?? null,
                (float) $record['currentTime'],
                (int) $record['duration'],
            );

            $row = WatchProgress::query()->updateOrCreate(
                [
                    'user_id' => $user->id,
                    'media_id' => (int) $record['mediaId'],
                    'episode_key' => $episodeKey,
                ],
                [
                    'media_type' => ! empty($record['episodeId']) ? 'series' : 'movie',
                    'episode_id' => ! empty($record['episodeId']) ? (int) $record['episodeId'] : null,
                    'season_number' => $record['seasonNumber'] ?? null,
                    'episode_number' => $record['episodeNumber'] ?? null,
                    'current_time' => (float) $record['currentTime'],
                    'duration' => (int) round((float) $record['duration']),
                    'percent_watched' => $percent,
                    'is_completed' => (bool) ($record['isCompleted'] ?? ($percent >= 90)),
                    'platform' => $record['platform'] ?? 'WEB',
                    'device_id' => $record['deviceId'] ?? $request->header('X-Device-Id'),
                    'last_watched_at' => Carbon::parse($record['lastWatchedAt']),
                ],
            );

            $migrated[] = $this->transform($row);
        }

        return response()->json([
            'success' => true,
            'data' => ['migrated' => $migrated, 'count' => count($migrated)],
        ]);
    }

    public function destroy(Request $request, string $mediaId): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 401);
        }

        $episodeId = $request->query('episodeId');

        WatchProgress::query()
            ->where('user_id', $user->id)
            ->forMedia($mediaId, is_string($episodeId) ? $episodeId : null)
            ->delete();

        return response()->json(['success' => true]);
    }

    private function resolveUser(Request $request): ?User
    {
        $user = $request->user();

        return $user instanceof User && $user->status !== 'banned' ? $user : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request): array
    {
        return Validator::make($request->all(), [
            'mediaId' => ['required', 'integer', 'min:1'],
            'episodeId' => ['nullable', 'string'],
            'currentTime' => ['required', 'numeric', 'min:0'],
            'duration' => ['required', 'numeric', 'min:0'],
            'percentWatched' => ['nullable', 'numeric', 'min:0'],
            'isCompleted' => ['nullable', 'boolean'],
            'profileId' => ['nullable', 'string'],
            'seasonNumber' => ['nullable', 'integer', 'min:1'],
            'episodeNumber' => ['nullable', 'integer', 'min:1'],
            'platform' => ['nullable', Rule::in(['WEB', 'MOBILE', 'TV'])],
            'deviceId' => ['nullable', 'string', 'max:64'],
            'lastWatchedAt' => ['required', 'date'],
        ])->validate();
    }

    private function normalizePercent(?float $percentWatched, float $currentTime, int $duration): float
    {
        if ($percentWatched !== null) {
            return $percentWatched <= 1
                ? round($percentWatched * 100, 2)
                : round($percentWatched, 2);
        }

        if ($duration <= 0) {
            return 0;
        }

        return round(min(100, ($currentTime / $duration) * 100), 2);
    }

    /**
     * @return array<string, mixed>
     */
    private function transform(WatchProgress $row): array
    {
        return [
            'mediaId' => (string) $row->media_id,
            'mediaType' => $row->media_type,
            'episodeId' => $row->episode_id ? (string) $row->episode_id : null,
            'seasonNumber' => $row->season_number,
            'episodeNumber' => $row->episode_number,
            'currentTime' => (float) $row->current_time,
            'duration' => (int) $row->duration,
            'percentWatched' => (float) $row->percent_watched,
            'isCompleted' => (bool) $row->is_completed,
            'platform' => $row->platform,
            'deviceId' => $row->device_id,
            'lastWatchedAt' => $row->last_watched_at?->toIso8601String(),
            'syncedAt' => $row->updated_at?->toIso8601String(),
            ...$this->resolveDisplayMetadata($row),
        ];
    }

    /**
     * @return array{slug?: string, seriesSlug?: string, title?: string, posterUrl?: string|null}
     */
    private function resolveDisplayMetadata(WatchProgress $row): array
    {
        if ($row->media_type === 'series') {
            $series = Series::query()->find($row->media_id);
            if ($series === null) {
                return [];
            }

            $title = $series->title;
            $posterUrl = $series->poster_url;

            if ($row->episode_id) {
                $episode = SeriesEpisode::query()->find($row->episode_id);
                if ($episode !== null) {
                    $title = trim($series->title.' — '.$episode->title);
                    $posterUrl = $episode->still_url ?: $posterUrl;
                }
            }

            return [
                'slug' => $series->slug,
                'seriesSlug' => $series->slug,
                'title' => $title,
                'posterUrl' => $posterUrl,
            ];
        }

        $movie = Movie::query()->find($row->media_id);
        if ($movie === null) {
            return [];
        }

        return [
            'slug' => $movie->slug,
            'title' => $movie->title,
            'posterUrl' => $movie->poster_url,
        ];
    }
}

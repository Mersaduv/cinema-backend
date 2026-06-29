<?php

namespace App\Services\Cinema;

use App\Jobs\PublishScheduledItemJob;
use App\Jobs\RefreshTrendingJob;
use App\Models\Movie;
use App\Models\Series;
use Carbon\Carbon;
use Illuminate\Support\Facades\Date;

class PublishScheduleService
{
    public function __construct(
        private readonly CatalogCacheService $catalogCache,
        private readonly CatalogContentRankingSyncService $catalogContentRankingSyncService,
    ) {
    }

    /**
     * @return array{publish_status: string, scheduled_for: ?Carbon, published_at: ?Carbon}
     */
    public function resolvePublishState(string $publishStatus, mixed $scheduledFor, ?Carbon $existingPublishedAt = null): array
    {
        if ($publishStatus !== 'scheduled') {
            return [
                'publish_status' => $publishStatus,
                'scheduled_for' => null,
                'published_at' => $publishStatus === 'published'
                    ? ($existingPublishedAt ?? Date::now())
                    : null,
            ];
        }

        $scheduledAt = $this->parseScheduledFor($scheduledFor);

        if ($scheduledAt === null) {
            return [
                'publish_status' => 'scheduled',
                'scheduled_for' => null,
                'published_at' => null,
            ];
        }

        if ($scheduledAt->lte(Date::now())) {
            return [
                'publish_status' => 'published',
                'scheduled_for' => $scheduledAt,
                'published_at' => $existingPublishedAt ?? Date::now(),
            ];
        }

        return [
            'publish_status' => 'scheduled',
            'scheduled_for' => $scheduledAt,
            'published_at' => null,
        ];
    }

    public function parseScheduledFor(mixed $value): ?Carbon
    {
        if (blank($value)) {
            return null;
        }

        $timezone = (string) config('app.timezone', 'UTC');

        if ($value instanceof Carbon) {
            return $value->copy()->timezone($timezone)->seconds(0);
        }

        if (! is_string($value)) {
            return Date::parse($value)->timezone($timezone)->seconds(0);
        }

        $trimmed = trim($value);

        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $trimmed) === 1) {
            return Carbon::createFromFormat('Y-m-d\TH:i', $trimmed, $timezone);
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', $trimmed) === 1) {
            return Carbon::createFromFormat('Y-m-d\TH:i', substr($trimmed, 0, 16), $timezone);
        }

        return Date::parse($trimmed)->timezone($timezone)->seconds(0);
    }

    public function formatScheduleForApi(?Carbon $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value->copy()->timezone((string) config('app.timezone', 'UTC'))->format('Y-m-d\TH:i');
    }

    public function syncScheduledPublishRunner(Movie|Series $content): void
    {
        $content->refresh();

        if ($content->publish_status !== 'scheduled' || $content->scheduled_for === null) {
            return;
        }

        $scheduledAt = $this->parseScheduledFor($content->scheduled_for);

        if ($scheduledAt === null) {
            return;
        }

        if ($scheduledAt->lte(Date::now())) {
            $this->publishDueContent();

            return;
        }

        $contentType = $content instanceof Series ? 'series' : 'movie';

        PublishScheduledItemJob::dispatch($contentType, $content->id)
            ->delay($scheduledAt);
    }

    public function publishScheduledItem(string $contentType, int $contentId): bool
    {
        if ($contentType === 'series') {
            $series = Series::query()->find($contentId);

            if ($series === null || $series->publish_status !== 'scheduled' || $series->scheduled_for === null) {
                return false;
            }

            if (! $this->isScheduleDue($series->scheduled_for, Date::now())) {
                return false;
            }

            $series->update([
                'publish_status' => 'published',
                'published_at' => Date::now(),
            ]);

            $this->catalogCache->scheduleRebuildAfterContentChange();
            $this->catalogContentRankingSyncService->syncOnPublish($series->fresh());

            return true;
        }

        if ($contentType !== 'movie') {
            return false;
        }

        $movie = Movie::query()->find($contentId);

        if ($movie === null || $movie->publish_status !== 'scheduled' || $movie->scheduled_for === null) {
            return false;
        }

        if (! $this->isScheduleDue($movie->scheduled_for, Date::now())) {
            return false;
        }

        $movie->update([
            'publish_status' => 'published',
            'published_at' => Date::now(),
        ]);

        $this->catalogCache->scheduleRebuildAfterContentChange();
        $this->catalogContentRankingSyncService->syncOnPublish($movie->fresh());

        return true;
    }

    /**
     * @return array{movies: int, series: int}
     */
    public function publishDueContent(?Carbon $asOf = null): array
    {
        $moment = $asOf ?? Date::now();
        $movieCount = 0;
        $seriesCount = 0;

        Movie::query()
            ->where('publish_status', 'scheduled')
            ->whereNotNull('scheduled_for')
            ->orderBy('id')
            ->chunkById(100, function ($movies) use ($moment, &$movieCount) {
                foreach ($movies as $movie) {
                    if (! $this->isScheduleDue($movie->scheduled_for, $moment)) {
                        continue;
                    }

                    $movie->update([
                        'publish_status' => 'published',
                        'published_at' => $moment,
                    ]);
                    $this->catalogContentRankingSyncService->syncOnPublish($movie->fresh());
                    $movieCount++;
                }
            });

        Series::query()
            ->where('publish_status', 'scheduled')
            ->whereNotNull('scheduled_for')
            ->orderBy('id')
            ->chunkById(100, function ($seriesItems) use ($moment, &$seriesCount) {
                foreach ($seriesItems as $series) {
                    if (! $this->isScheduleDue($series->scheduled_for, $moment)) {
                        continue;
                    }

                    $series->update([
                        'publish_status' => 'published',
                        'published_at' => $moment,
                    ]);
                    $this->catalogContentRankingSyncService->syncOnPublish($series->fresh());
                    $seriesCount++;
                }
            });

        if ($movieCount > 0 || $seriesCount > 0) {
            $this->catalogCache->scheduleRebuildAfterContentChange();
            RefreshTrendingJob::dispatch('day')->afterResponse();
        }

        return [
            'movies' => $movieCount,
            'series' => $seriesCount,
        ];
    }

    private function isScheduleDue(mixed $scheduledFor, Carbon $moment): bool
    {
        $scheduledAt = $scheduledFor instanceof Carbon
            ? $scheduledFor
            : $this->parseScheduledFor($scheduledFor);

        return $scheduledAt !== null && $scheduledAt->lte($moment);
    }
}

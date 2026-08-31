<?php

namespace App\Services\Cinema;

use App\Models\Movie;
use App\Models\Series;
use Illuminate\Support\Facades\DB;

/**
 * Lightweight view counter with dedupe key.
 *
 * We store a per-watch-segment `view_session_id` in `content_view_keys`
 * (deduped by DB unique constraint) and increment a denormalized
 * `views_count` on the content row for admin display.
 */
class ContentViewService
{
    public const MIN_WATCH_SECONDS = 10;

    /**
     * Record a content "view" (one increment per view_session_id).
     *
     * We store a lightweight dedupe key in `content_view_keys` so the same
     * client segment can't double-count due to retries/re-renders.
     *
     * @return array{recorded: bool, views_count: int}
     */
    public function record(
        string $mediaType,
        int $mediaId,
        string $viewSessionId,
    ): array {
        $viewSessionId = trim($viewSessionId);

        if ($viewSessionId === '' || strlen($viewSessionId) > 64 || ! $this->isPublished($mediaType, $mediaId)) {
            return [
                'recorded' => false,
                'views_count' => $this->getCount($mediaType, $mediaId),
            ];
        }

        $inserted = DB::table('content_view_keys')->insertOrIgnore([
            'media_type' => $mediaType,
            'media_id' => $mediaId,
            // DB column is named viewer_key, but we intentionally store the view session id here.
            // This lets the same device/viewer count multiple times across different watch segments.
            'viewer_key' => $viewSessionId,
            'created_at' => now(),
        ]);

        if ($inserted === 1) {
            $this->incrementCount($mediaType, $mediaId);
        }

        return [
            'recorded' => $inserted === 1,
            'views_count' => $this->getCount($mediaType, $mediaId),
        ];
    }

    public function getCount(string $mediaType, int $mediaId): int
    {
        if ($mediaType === 'movie') {
            return (int) Movie::query()->whereKey($mediaId)->value('views_count');
        }

        if ($mediaType === 'series') {
            return (int) Series::query()->whereKey($mediaId)->value('views_count');
        }

        return 0;
    }

    private function isPublished(string $mediaType, int $mediaId): bool
    {
        if ($mediaType === 'movie') {
            return Movie::query()
                ->whereKey($mediaId)
                ->where('publish_status', 'published')
                ->exists();
        }

        if ($mediaType === 'series') {
            return Series::query()
                ->whereKey($mediaId)
                ->where('publish_status', 'published')
                ->exists();
        }

        return false;
    }

    private function incrementCount(string $mediaType, int $mediaId): void
    {
        if ($mediaType === 'movie') {
            Movie::query()->whereKey($mediaId)->increment('views_count');

            return;
        }

        if ($mediaType === 'series') {
            Series::query()->whereKey($mediaId)->increment('views_count');
        }
    }
}

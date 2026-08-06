<?php

namespace App\Services\Cinema;

use App\Models\Movie;
use App\Models\Series;
use Illuminate\Support\Facades\Cache;

class AdminCatalogSummaryService
{
    /**
     * @return array{
     *     total: int,
     *     drafts: int,
     *     published: int,
     *     processing: int,
     *     ready: int
     * }
     */
    public function movieSummary(): array
    {
        return Cache::remember('admin_movie_summary_v1', 60, function (): array {
            $row = Movie::query()
                ->selectRaw('COUNT(*) as total')
                ->selectRaw("SUM(CASE WHEN publish_status = 'draft' THEN 1 ELSE 0 END) as drafts")
                ->selectRaw("SUM(CASE WHEN publish_status = 'published' THEN 1 ELSE 0 END) as published")
                ->selectRaw("SUM(CASE WHEN processing_status IN ('queued', 'processing', 'pending') OR pipeline_status IN ('pending', 'running') THEN 1 ELSE 0 END) as processing")
                ->selectRaw("SUM(CASE WHEN processing_status = 'ready' AND (pipeline_status IS NULL OR pipeline_status IN ('idle', 'completed')) THEN 1 ELSE 0 END) as ready")
                ->first();

            return [
                'total' => (int) ($row->total ?? 0),
                'drafts' => (int) ($row->drafts ?? 0),
                'published' => (int) ($row->published ?? 0),
                'processing' => (int) ($row->processing ?? 0),
                'ready' => (int) ($row->ready ?? 0),
            ];
        });
    }

    /**
     * @return array{
     *     total: int,
     *     drafts: int,
     *     scheduled: int,
     *     published: int,
     *     featured: int
     * }
     */
    public function seriesSummary(): array
    {
        return Cache::remember('admin_series_summary_v1', 60, function (): array {
            $row = Series::query()
                ->selectRaw('COUNT(*) as total')
                ->selectRaw("SUM(CASE WHEN publish_status = 'draft' THEN 1 ELSE 0 END) as drafts")
                ->selectRaw("SUM(CASE WHEN publish_status = 'scheduled' THEN 1 ELSE 0 END) as scheduled")
                ->selectRaw("SUM(CASE WHEN publish_status = 'published' THEN 1 ELSE 0 END) as published")
                ->selectRaw('SUM(CASE WHEN is_featured = 1 THEN 1 ELSE 0 END) as featured')
                ->first();

            return [
                'total' => (int) ($row->total ?? 0),
                'drafts' => (int) ($row->drafts ?? 0),
                'scheduled' => (int) ($row->scheduled ?? 0),
                'published' => (int) ($row->published ?? 0),
                'featured' => (int) ($row->featured ?? 0),
            ];
        });
    }

    public function invalidateMovies(): void
    {
        Cache::forget('admin_movie_summary_v1');
    }

    public function invalidateSeries(): void
    {
        Cache::forget('admin_series_summary_v1');
    }
}

<?php

namespace App\Services\Cinema;

use App\Models\Collection;
use App\Models\ContentRequest;
use App\Models\Review;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

class AdminDashboardService
{
    public function __construct(
        private readonly AdminCatalogSummaryService $catalogSummary,
    ) {
    }

    /**
     * @return array{
     *     movies: array<string, int>,
     *     series: array<string, int>,
     *     collections: int,
     *     users: int,
     *     reviews_pending: int,
     *     tickets_waiting: int,
     *     requests_pending: int
     * }
     */
    public function summary(): array
    {
        return Cache::remember('admin_dashboard_summary_v1', 60, function (): array {
            return [
                'movies' => $this->catalogSummary->movieSummary(),
                'series' => $this->catalogSummary->seriesSummary(),
                'collections' => Collection::query()->count(),
                'users' => User::query()->count(),
                'reviews_pending' => Review::query()
                    ->where('status', Review::STATUS_PENDING)
                    ->count(),
                'tickets_waiting' => SupportTicket::query()
                    ->where('status', SupportTicket::STATUS_WAITING)
                    ->count(),
                'requests_pending' => ContentRequest::query()
                    ->where('status', ContentRequest::STATUS_PENDING)
                    ->count(),
            ];
        });
    }

    public function invalidate(): void
    {
        Cache::forget('admin_dashboard_summary_v1');
    }
}

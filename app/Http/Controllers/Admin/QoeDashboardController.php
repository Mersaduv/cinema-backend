<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class QoeDashboardController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $from = $request->query('from', now()->subDays(7)->toDateString());
        $to = $request->query('to', now()->toDateString());

        $fromDate = $from.' 00:00:00';
        $toDate = $to.' 23:59:59';

        return response()->json([
            'success' => true,
            'data' => [
                'from' => $from,
                'to' => $to,
                'engine_distribution' => DB::table('playback_events')
                    ->whereBetween('created_at', [$fromDate, $toDate])
                    ->where('event_type', 'session_start')
                    ->groupBy('engine_id')
                    ->selectRaw('engine_id, COUNT(*) as sessions')
                    ->orderByDesc('sessions')
                    ->get(),
                'avg_startup_ms' => DB::table('playback_events')
                    ->whereBetween('created_at', [$fromDate, $toDate])
                    ->where('event_type', 'startup')
                    ->whereNotNull('startup_ms')
                    ->groupBy('platform_family')
                    ->selectRaw('platform_family, AVG(startup_ms) as avg_ms, COUNT(*) as count')
                    ->orderBy('platform_family')
                    ->get(),
                'rebuffer_rate' => DB::table('playback_events')
                    ->whereBetween('created_at', [$fromDate, $toDate])
                    ->where('event_type', 'rebuffer')
                    ->groupBy('platform_family')
                    ->selectRaw('platform_family, SUM(rebuffer_count) as total_rebuffers, AVG(rebuffer_duration_ms) as avg_duration_ms')
                    ->orderBy('platform_family')
                    ->get(),
                'top_errors' => DB::table('playback_events')
                    ->whereBetween('created_at', [$fromDate, $toDate])
                    ->where('event_type', 'error')
                    ->groupBy('error_layer', 'error_code')
                    ->selectRaw('error_layer, error_code, COUNT(*) as count')
                    ->orderByDesc('count')
                    ->limit(20)
                    ->get(),
            ],
        ]);
    }
}

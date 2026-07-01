<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Cinema\AdminDashboardService;
use Illuminate\Http\JsonResponse;

class AdminDashboardController extends Controller
{
    public function __construct(
        private readonly AdminDashboardService $dashboardService,
    ) {
    }

    public function summary(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->dashboardService->summary(),
        ]);
    }
}

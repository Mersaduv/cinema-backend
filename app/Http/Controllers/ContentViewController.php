<?php

namespace App\Http\Controllers;

use App\Models\Viewer;
use App\Services\Cinema\ContentViewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ContentViewController extends Controller
{
    public function __construct(
        private readonly ContentViewService $contentViewService,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'media_type' => ['required', Rule::in(['movie', 'series'])],
            'media_id' => ['required', 'integer', 'min:1'],
            'view_session_id' => ['required', 'string', 'min:8', 'max:64'],
            'device_id' => ['nullable', 'string', 'min:8', 'max:64'],
            'watch_seconds' => ['required', 'numeric', 'min:0'],
        ])->validate();

        if ((float) $validated['watch_seconds'] < ContentViewService::MIN_WATCH_SECONDS) {
            return response()->json([
                'success' => false,
                'message' => 'حداقل زمان تماشا برای ثبت بازدید رعایت نشده است.',
            ], 422);
        }

        $viewer = $request->user();
        $viewerId = $viewer instanceof Viewer ? (int) $viewer->id : null;
        $deviceId = $validated['device_id']
            ?? $request->header('X-Device-Id');

        if (! $viewerId && ! is_string($deviceId)) {
            return response()->json([
                'success' => false,
                'message' => 'شناسه دستگاه الزامی است.',
            ], 422);
        }

        $result = $this->contentViewService->record(
            (string) $validated['media_type'],
            (int) $validated['media_id'],
            (string) $validated['view_session_id'],
        );

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }
}

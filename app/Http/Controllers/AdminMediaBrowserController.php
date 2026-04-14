<?php

namespace App\Http\Controllers;

use App\Services\Cinema\MediaPathService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class AdminMediaBrowserController extends Controller
{
    public function __construct(
        private readonly MediaPathService $mediaPathService,
    ) {
    }

    public function roots(Request $request): JsonResponse
    {
        $kind = $this->resolveKind($request);

        return response()->json([
            'success' => true,
            'data' => [
                'kind' => $kind,
                'roots' => $this->mediaPathService->browseRoots(),
                'allowed_extensions' => $this->mediaPathService->allowedExtensionsForKind($kind),
            ],
        ]);
    }

    public function entries(Request $request): JsonResponse
    {
        $kind = $this->resolveKind($request);
        $path = $request->query('path');

        try {
            $entries = $this->mediaPathService->browseDirectory(
                is_string($path) ? $path : null,
                $kind
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'errors' => [
                    'path' => [$exception->getMessage()],
                ],
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'kind' => $kind,
                'allowed_extensions' => $this->mediaPathService->allowedExtensionsForKind($kind),
                ...$entries,
            ],
        ]);
    }

    private function resolveKind(Request $request): string
    {
        $kind = strtolower((string) $request->query('kind', 'video'));

        return in_array($kind, ['video', 'image', 'subtitle', 'dubbed'], true) ? $kind : 'video';
    }
}

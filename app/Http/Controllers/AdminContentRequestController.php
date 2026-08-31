<?php

namespace App\Http\Controllers;

use App\Models\ContentRequest;
use App\Services\Cinema\ContentRequestPresentationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminContentRequestController extends Controller
{
  public function __construct(
    private readonly ContentRequestPresentationService $presentationService,
  ) {
  }

  public function index(Request $request): JsonResponse
  {
    $perPage = min(max((int) $request->integer('per_page', 20), 1), 50);

    $paginator = ContentRequest::query()
      ->with('viewer')
      ->when($request->filled('status'), function ($query) use ($request): void {
        $query->where('status', (string) $request->string('status'));
      })
      ->when($request->filled('search'), function ($query) use ($request): void {
        $search = trim((string) $request->string('search'));
        $query->where(function ($inner) use ($search): void {
          $inner
            ->where('title', 'like', '%' . $search . '%')
            ->orWhere('description', 'like', '%' . $search . '%')
            ->orWhereHas('viewer', fn ($viewerQuery) => $viewerQuery
              ->where('name', 'like', '%' . $search . '%')
              ->orWhere('email', 'like', '%' . $search . '%'));
        });
      })
      ->orderByDesc('created_at')
      ->paginate($perPage);

    return response()->json([
      'success' => true,
      'data' => [
        'requests' => collect($paginator->items())
          ->map(fn (ContentRequest $item) => $this->presentationService->adminTransform($item))
          ->values(),
        'pagination' => [
          'current_page' => $paginator->currentPage(),
          'last_page' => $paginator->lastPage(),
          'per_page' => $paginator->perPage(),
          'total' => $paginator->total(),
        ],
      ],
    ]);
  }

  public function update(Request $request, ContentRequest $contentRequest): JsonResponse
  {
    $validated = $request->validate([
      'status' => [
        'sometimes',
        'string',
        Rule::in([
          ContentRequest::STATUS_PENDING,
          ContentRequest::STATUS_IN_PROGRESS,
          ContentRequest::STATUS_FULFILLED,
          ContentRequest::STATUS_REJECTED,
        ]),
      ],
      'admin_note' => ['sometimes', 'nullable', 'string', 'max:2000'],
    ]);

    $updates = [];

    if (array_key_exists('status', $validated)) {
      $updates['status'] = $validated['status'];
      $updates['moderated_at'] = now();
    }

    if (array_key_exists('admin_note', $validated)) {
      $updates['admin_note'] = $validated['admin_note'];
    }

    if ($updates !== []) {
      $contentRequest->update($updates);
    }

    return response()->json([
      'success' => true,
      'message' => 'درخواست به‌روزرسانی شد.',
      'data' => [
        'request' => $this->presentationService->adminTransform($contentRequest->fresh('viewer')),
      ],
    ]);
  }
}

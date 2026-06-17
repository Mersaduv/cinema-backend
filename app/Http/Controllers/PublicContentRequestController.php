<?php

namespace App\Http\Controllers;

use App\Models\ContentRequest;
use App\Models\User;
use App\Services\Cinema\ContentRequestPresentationService;
use App\Support\ContentRequestValidation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PublicContentRequestController extends Controller
{
  public function __construct(
    private readonly ContentRequestPresentationService $presentationService,
  ) {
  }

  public function index(Request $request): JsonResponse
  {
    $user = $request->user();

    if (! $user instanceof User || $user->status === 'banned') {
      return response()->json(['success' => false, 'message' => 'Unauthorized.'], 401);
    }

    $perPage = min(max((int) $request->integer('per_page', 20), 1), 50);
    $page = max(1, (int) $request->integer('page', 1));

    $paginator = ContentRequest::query()
      ->forUser((int) $user->id)
      ->when($request->filled('status'), function ($query) use ($request): void {
        $query->where('status', (string) $request->string('status'));
      })
      ->orderByDesc('created_at')
      ->paginate($perPage, ['*'], 'page', $page);

    return response()->json([
      'success' => true,
      'data' => [
        'requests' => collect($paginator->items())
          ->map(fn (ContentRequest $item) => $this->presentationService->transform($item, $user))
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

  public function store(Request $request): JsonResponse
  {
    $user = $request->user();

    if (! $user instanceof User || $user->status === 'banned') {
      return response()->json(['success' => false, 'message' => 'Unauthorized.'], 401);
    }

    $validated = Validator::make(
      $request->all(),
      ContentRequestValidation::submitRules(),
      [
        'title.required' => 'نام فیلم یا سریال الزامی است.',
        'title.min' => 'نام فیلم یا سریال خیلی کوتاه است.',
        'genre_slug.required' => 'انتخاب ژانر الزامی است.',
        'genre_slug.in' => 'ژانر انتخاب‌شده معتبر نیست.',
        'description.required' => 'توضیحات الزامی است.',
        'description.min' => 'توضیحات باید حداقل ۱۰ کاراکتر باشد.',
      ],
    )->validate();

    $genreName = ContentRequestValidation::resolveGenreName($validated['genre_slug']);

    if ($genreName === null) {
      return response()->json([
        'success' => false,
        'message' => 'ژانر انتخاب‌شده معتبر نیست.',
      ], 422);
    }

    $contentRequest = ContentRequest::query()->create([
      'user_id' => $user->id,
      'title' => trim($validated['title']),
      'genre_slug' => $validated['genre_slug'],
      'genre_name' => $genreName,
      'description' => ContentRequestValidation::sanitizeDescription($validated['description']),
      'status' => ContentRequest::STATUS_PENDING,
    ]);

    return response()->json([
      'success' => true,
      'message' => 'درخواست شما با موفقیت ثبت شد.',
      'data' => [
        'request' => $this->presentationService->transform($contentRequest, $user),
      ],
    ], 201);
  }
}

<?php

namespace App\Http\Controllers;

use App\Models\Viewer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class AdminViewerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $search = trim((string) $request->string('search'));
        $status = (string) $request->string('status');
        $perPage = min(max((int) $request->integer('per_page', 12), 1), 50);

        $query = Viewer::query()
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($innerQuery) use ($search) {
                    $innerQuery
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->when(in_array($status, ['active', 'banned'], true), function ($query) use ($status) {
                $query->where('status', $status);
            })
            ->latest();

        $viewers = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => [
                'viewers' => collect($viewers->items())->map(fn (Viewer $viewer) => $this->transform($viewer))->values(),
                'statuses' => [
                    ['value' => 'active', 'label' => 'فعال'],
                    ['value' => 'banned', 'label' => 'مسدود'],
                ],
                'summary' => [
                    'total_viewers' => Viewer::count(),
                    'active_viewers' => Viewer::where('status', 'active')->count(),
                    'banned_viewers' => Viewer::where('status', 'banned')->count(),
                ],
                'pagination' => [
                    'current_page' => $viewers->currentPage(),
                    'last_page' => $viewers->lastPage(),
                    'per_page' => $viewers->perPage(),
                    'total' => $viewers->total(),
                ],
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('viewers', 'email'),
                Rule::unique('users', 'email'),
            ],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'status' => ['required', Rule::in(['active', 'banned'])],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $viewer = Viewer::create([
            'name' => (string) $request->string('name'),
            'email' => (string) $request->string('email'),
            'password' => Hash::make((string) $request->string('password')),
            'status' => (string) $request->string('status'),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Viewer created successfully.',
            'data' => [
                'viewer' => $this->transform($viewer),
            ],
        ], 201);
    }

    public function updateStatus(Request $request, Viewer $viewer): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'status' => ['required', Rule::in(['active', 'banned'])],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $viewer->status = (string) $request->string('status');
        $viewer->save();

        return response()->json([
            'success' => true,
            'message' => 'وضعیت بیننده به‌روزرسانی شد.',
            'data' => [
                'viewer' => $this->transform($viewer),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function transform(Viewer $viewer): array
    {
        return [
            'id' => $viewer->id,
            'name' => $viewer->name,
            'email' => $viewer->email,
            'status' => $viewer->status,
            'created_at' => $viewer->created_at?->toISOString(),
        ];
    }
}

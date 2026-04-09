<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class AdminUserController extends Controller
{
    /**
     * @var array<int, string>
     */
    private array $allowedRoleSlugs = ['admin', 'support', 'user'];

    public function index(Request $request): JsonResponse
    {
        $search = trim((string) $request->string('search'));
        $role = (string) $request->string('role');
        $status = (string) $request->string('status');
        $perPage = min(max((int) $request->integer('per_page', 12), 1), 50);

        $query = User::query()
            ->with(['roles' => function ($query) {
                $query->select('roles.id', 'roles.name', 'roles.slug', 'roles.description');
            }])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($innerQuery) use ($search) {
                    $innerQuery
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->when(in_array($role, $this->allowedRoleSlugs, true), function ($query) use ($role) {
                $query->whereHas('roles', function ($roleQuery) use ($role) {
                    $roleQuery->where('slug', $role);
                });
            })
            ->when(in_array($status, ['active', 'banned'], true), function ($query) use ($status) {
                $query->where('status', $status);
            })
            ->latest();

        $users = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => [
                'users' => collect($users->items())->map(function (User $user) {
                    return $this->transformUser($user);
                })->values(),
                'roles' => $this->availableRoles(),
                'statuses' => [
                    ['value' => 'active', 'label' => 'فعال'],
                    ['value' => 'banned', 'label' => 'مسدود'],
                ],
                'summary' => [
                    'total_users' => User::count(),
                    'active_users' => User::where('status', 'active')->count(),
                    'admins' => $this->countUsersByRole('admin'),
                    'support_users' => $this->countUsersByRole('support'),
                ],
                'pagination' => [
                    'current_page' => $users->currentPage(),
                    'last_page' => $users->lastPage(),
                    'per_page' => $users->perPage(),
                    'total' => $users->total(),
                ],
            ],
        ]);
    }

    public function options(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'roles' => $this->availableRoles(),
                'statuses' => [
                    ['value' => 'active', 'label' => 'فعال'],
                    ['value' => 'banned', 'label' => 'مسدود'],
                ],
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'role_slug' => ['required', 'string', Rule::in($this->allowedRoleSlugs)],
            'status' => ['required', Rule::in(['active', 'banned'])],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $role = Role::where('slug', $request->string('role_slug'))->first();

        if (!$role) {
            return response()->json([
                'success' => false,
                'message' => 'Selected role is not available.',
            ], 422);
        }

        $user = DB::transaction(function () use ($request, $role) {
            $user = User::create([
                'name' => (string) $request->string('name'),
                'email' => (string) $request->string('email'),
                'password' => Hash::make((string) $request->string('password')),
                'status' => (string) $request->string('status'),
            ]);

            $user->roles()->sync([$role->id]);

            return $user->load(['roles' => function ($query) {
                $query->select('roles.id', 'roles.name', 'roles.slug', 'roles.description');
            }]);
        });

        return response()->json([
            'success' => true,
            'message' => 'User created successfully.',
            'data' => [
                'user' => $this->transformUser($user),
            ],
        ], 201);
    }

    private function countUsersByRole(string $roleSlug): int
    {
        return User::whereHas('roles', function ($query) use ($roleSlug) {
            $query->where('slug', $roleSlug);
        })->count();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function availableRoles(): array
    {
        $order = array_flip($this->allowedRoleSlugs);

        return Role::query()
            ->whereIn('slug', $this->allowedRoleSlugs)
            ->get(['id', 'name', 'slug', 'description'])
            ->sortBy(fn (Role $role) => $order[$role->slug] ?? PHP_INT_MAX)
            ->values()
            ->map(function (Role $role) {
                return [
                    'id' => $role->id,
                    'name' => $role->name,
                    'slug' => $role->slug,
                    'description' => $role->description,
                ];
            })
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function transformUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'status' => $user->status,
            'created_at' => $user->created_at?->toISOString(),
            'roles' => $user->roles->map(function (Role $role) {
                return [
                    'id' => $role->id,
                    'name' => $role->name,
                    'slug' => $role->slug,
                    'description' => $role->description,
                ];
            })->values()->all(),
        ];
    }
}

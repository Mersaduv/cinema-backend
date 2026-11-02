<?php

use App\Models\Permission;
use App\Models\Role;
use App\Support\AdminPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (AdminPermissions::all() as $permissionData) {
            Permission::query()->updateOrCreate(
                ['slug' => $permissionData['slug']],
                [
                    'name' => $permissionData['name'],
                    'description' => $permissionData['description'],
                ]
            );
        }

        $permissions = Permission::query()
            ->whereIn('slug', AdminPermissions::allSlugs())
            ->get()
            ->keyBy('slug');

        foreach (AdminPermissions::roleDefaults() as $roleSlug => $permissionSlugs) {
            $role = Role::query()->where('slug', $roleSlug)->first();
            if (! $role) {
                continue;
            }

            $ids = collect($permissionSlugs)
                ->map(fn (string $slug) => $permissions->get($slug)?->id)
                ->filter()
                ->values()
                ->all();

            $role->permissions()->sync($ids);
        }

        // Ensure existing admin users with direct permissions also get the new slugs.
        $newPermissionIds = Permission::query()
            ->whereIn('slug', ['edit_users', 'delete_users'])
            ->pluck('id')
            ->all();

        if ($newPermissionIds === []) {
            return;
        }

        $adminUserIds = DB::table('role_user')
            ->join('roles', 'roles.id', '=', 'role_user.role_id')
            ->where('roles.slug', 'admin')
            ->pluck('role_user.user_id')
            ->unique()
            ->all();

        foreach ($adminUserIds as $userId) {
            $hasDirect = DB::table('permission_user')
                ->where('user_id', $userId)
                ->exists();

            if (! $hasDirect) {
                continue;
            }

            foreach ($newPermissionIds as $permissionId) {
                DB::table('permission_user')->updateOrInsert(
                    [
                        'user_id' => $userId,
                        'permission_id' => $permissionId,
                    ],
                    []
                );
            }
        }
    }

    public function down(): void
    {
        $permissionIds = Permission::query()
            ->whereIn('slug', ['edit_users', 'delete_users'])
            ->pluck('id');

        if ($permissionIds->isEmpty()) {
            return;
        }

        DB::table('permission_user')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
        Permission::query()->whereIn('id', $permissionIds)->delete();
    }
};

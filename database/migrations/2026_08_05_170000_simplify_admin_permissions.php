<?php

use App\Models\Permission;
use App\Models\Role;
use App\Support\AdminPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (AdminPermissions::all() as $permissionData) {
            Permission::updateOrCreate(
                ['slug' => $permissionData['slug']],
                $permissionData
            );
        }

        $permissionsBySlug = Permission::query()
            ->get(['id', 'slug'])
            ->keyBy('slug');

        $this->remapDirectUserPermissions($permissionsBySlug);
        $this->syncRoleDefaults($permissionsBySlug);
        $this->deleteObsoletePermissions();
    }

    /**
     * @param  \Illuminate\Support\Collection<string, \App\Models\Permission>  $permissionsBySlug
     */
    private function remapDirectUserPermissions($permissionsBySlug): void
    {
        if (! Schema::hasTable('permission_user')) {
            return;
        }

        $rows = DB::table('permission_user')
            ->join('permissions', 'permissions.id', '=', 'permission_user.permission_id')
            ->select('permission_user.user_id', 'permissions.slug')
            ->get();

        if ($rows->isEmpty()) {
            return;
        }

        $map = AdminPermissions::obsoleteSlugMap();
        $validSlugs = array_flip(AdminPermissions::allSlugs());
        $userSlugs = [];

        foreach ($rows as $row) {
            $slug = $row->slug;
            if (isset($validSlugs[$slug])) {
                $mapped = $slug;
            } elseif (array_key_exists($slug, $map)) {
                $mapped = $map[$slug];
            } else {
                continue;
            }

            if ($mapped === null) {
                continue;
            }

            if (! isset($userSlugs[$row->user_id])) {
                $userSlugs[$row->user_id] = [];
            }

            $userSlugs[$row->user_id][$mapped] = true;
        }

        DB::table('permission_user')->delete();

        $now = now();
        foreach ($userSlugs as $userId => $slugSet) {
            foreach (array_keys($slugSet) as $slug) {
                $permissionId = $permissionsBySlug[$slug]->id ?? null;
                if (! $permissionId) {
                    continue;
                }

                DB::table('permission_user')->insert([
                    'user_id' => $userId,
                    'permission_id' => $permissionId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<string, \App\Models\Permission>  $permissionsBySlug
     */
    private function syncRoleDefaults($permissionsBySlug): void
    {
        foreach (AdminPermissions::roleDefaults() as $roleSlug => $permissionSlugs) {
            $role = Role::query()->where('slug', $roleSlug)->first();
            if (! $role) {
                continue;
            }

            $role->permissions()->sync(
                collect($permissionSlugs)
                    ->map(function (string $slug) use ($permissionsBySlug) {
                        return $permissionsBySlug[$slug]->id ?? null;
                    })
                    ->filter()
                    ->values()
                    ->all()
            );
        }
    }

    private function deleteObsoletePermissions(): void
    {
        $keep = AdminPermissions::allSlugs();

        Permission::query()
            ->whereNotIn('slug', $keep)
            ->get()
            ->each(function (Permission $permission) {
                $permission->roles()->detach();
                if (method_exists($permission, 'users')) {
                    $permission->users()->detach();
                }
                $permission->delete();
            });
    }

    public function down(): void
    {
        // Forward-only simplification of the permission tree.
    }
};

<?php

use App\Models\Permission;
use App\Models\Role;
use App\Support\AdminPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var array<string, array<int, string>>
     */
    private array $legacyMap = [
        'view_dashboard' => ['view_dashboard'],
        'manage_media' => ['view_movies', 'view_series', 'view_collections'],
        'process_media' => ['process_movies', 'process_series'],
        'manage_users' => ['view_users', 'create_users'],
        'create_movie' => ['create_movies', 'create_series'],
        'edit_movie' => ['edit_movies', 'edit_series', 'edit_collections'],
        'delete_movie' => ['delete_movies', 'delete_series', 'delete_collections'],
        'publish_movie' => ['publish_movies', 'publish_series'],
        'stream_video' => [],
    ];

    public function up(): void
    {
        foreach (AdminPermissions::all() as $permissionData) {
            Permission::updateOrCreate(
                ['slug' => $permissionData['slug']],
                $permissionData
            );
        }

        $permissionsBySlug = Permission::query()
            ->whereIn('slug', AdminPermissions::allSlugs())
            ->get()
            ->keyBy('slug');

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

        $this->migrateDirectUserPermissions($permissionsBySlug);

        Permission::query()
            ->whereNotIn('slug', AdminPermissions::allSlugs())
            ->whereIn('slug', array_keys($this->legacyMap))
            ->each(function (Permission $permission) {
                $permission->roles()->detach();
                $permission->users()->detach();
                $permission->delete();
            });
    }

    /**
     * @param  \Illuminate\Support\Collection<string, Permission>  $permissionsBySlug
     */
    private function migrateDirectUserPermissions($permissionsBySlug): void
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

        $userSlugs = [];

        foreach ($rows as $row) {
            $mapped = $this->legacyMap[$row->slug] ?? AdminPermissions::allSlugs();
            if ($mapped === []) {
                continue;
            }

            if (! isset($userSlugs[$row->user_id])) {
                $userSlugs[$row->user_id] = [];
            }

            $userSlugs[$row->user_id] = array_values(array_unique(array_merge(
                $userSlugs[$row->user_id],
                $mapped
            )));
        }

        DB::table('permission_user')->truncate();

        foreach ($userSlugs as $userId => $slugs) {
            $ids = collect($slugs)
                ->map(function (string $slug) use ($permissionsBySlug) {
                    return $permissionsBySlug[$slug]->id ?? null;
                })
                ->filter()
                ->values()
                ->all();

            if ($ids === []) {
                continue;
            }

            $now = now();
            foreach ($ids as $permissionId) {
                DB::table('permission_user')->insert([
                    'user_id' => $userId,
                    'permission_id' => $permissionId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Permissions are forward-only; seeder can restore legacy slugs if needed.
    }
};

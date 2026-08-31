<?php

use App\Models\Permission;
use App\Models\Role;
use App\Support\AdminPermissions;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $roleMeta = [
            'admin' => [
                'name' => 'Admin',
                'description' => 'مدیریت کامل پنل',
            ],
            'media' => [
                'name' => 'Media',
                'description' => 'مدیریت محتوای فیلم، سریال و مجموعه',
            ],
            'support' => [
                'name' => 'Support',
                'description' => 'پشتیبانی، دیدگاه، درخواست و تیکت',
            ],
        ];

        $permissionsBySlug = Permission::query()
            ->whereIn('slug', AdminPermissions::allSlugs())
            ->get()
            ->keyBy('slug');

        foreach (AdminPermissions::roleDefaults() as $roleSlug => $permissionSlugs) {
            $meta = $roleMeta[$roleSlug] ?? [
                'name' => ucfirst($roleSlug),
                'description' => null,
            ];

            $role = Role::updateOrCreate(
                ['slug' => $roleSlug],
                [
                    'name' => $meta['name'],
                    'description' => $meta['description'],
                ]
            );

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

    public function down(): void
    {
        // Keep roles; they are part of the staff RBAC model.
    }
};

<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Support\AdminPermissions;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
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

        $roles = [
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
            'user' => [
                'name' => 'User (legacy)',
                'description' => 'نقش قدیمی — مخاطبان اکنون در جدول viewers هستند',
            ],
        ];

        foreach ($roles as $slug => $roleData) {
            $role = Role::updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $roleData['name'],
                    'description' => $roleData['description'],
                ]
            );

            $defaultSlugs = AdminPermissions::roleDefaults()[$slug] ?? [];
            if ($slug === 'user') {
                $defaultSlugs = [];
            }

            $role->permissions()->sync(
                collect($defaultSlugs)
                    ->map(function (string $permissionSlug) use ($permissionsBySlug) {
                        return $permissionsBySlug[$permissionSlug]->id ?? null;
                    })
                    ->filter()
                    ->values()
                    ->all()
            );
        }
    }
}

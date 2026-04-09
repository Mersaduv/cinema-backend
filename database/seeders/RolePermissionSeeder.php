<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $roles = [
            'admin' => [
                'name' => 'Admin',
                'description' => 'مدیریت کاربران و پنل ادمین',
                'permissions' => [
                    'view_dashboard',
                    'manage_media',
                    'process_media',
                    'manage_users',
                    'create_movie',
                    'edit_movie',
                    'delete_movie',
                    'publish_movie',
                    'stream_video',
                ],
            ],
            'support' => [
                'name' => 'Support',
                'description' => 'پشتیبان سیستم برای پیگیری و بررسی',
                'permissions' => [
                    'view_dashboard',
                    'manage_media',
                    'stream_video',
                ],
            ],
            'user' => [
                'name' => 'User',
                'description' => 'کاربر عادی برای مشاهده محتوا',
                'permissions' => [
                    'stream_video',
                ],
            ],
        ];

        $permissions = [
            ['name' => 'Create Movie', 'slug' => 'create_movie', 'description' => 'ایجاد فیلم جدید'],
            ['name' => 'Edit Movie', 'slug' => 'edit_movie', 'description' => 'ویرایش فیلم'],
            ['name' => 'Delete Movie', 'slug' => 'delete_movie', 'description' => 'حذف فیلم'],
            ['name' => 'Publish Movie', 'slug' => 'publish_movie', 'description' => 'انتشار فیلم'],
            ['name' => 'Manage Media', 'slug' => 'manage_media', 'description' => 'مدیریت مدیا'],
            ['name' => 'Process Media', 'slug' => 'process_media', 'description' => 'شروع و پیگیری پردازش HLS'],
            ['name' => 'Manage Users', 'slug' => 'manage_users', 'description' => 'مدیریت کاربران'],
            ['name' => 'View Dashboard', 'slug' => 'view_dashboard', 'description' => 'مشاهده داشبورد'],
            ['name' => 'Stream Video', 'slug' => 'stream_video', 'description' => 'تماشای فیلم'],
        ];

        foreach ($permissions as $permissionData) {
            Permission::updateOrCreate(
                ['slug' => $permissionData['slug']],
                $permissionData
            );
        }

        $permissionsBySlug = Permission::query()
            ->whereIn('slug', collect($permissions)->pluck('slug'))
            ->get()
            ->keyBy('slug');

        foreach ($roles as $slug => $roleData) {
            $role = Role::updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $roleData['name'],
                    'description' => $roleData['description'],
                ]
            );

            $role->permissions()->sync(
                collect($roleData['permissions'])
                    ->map(fn (string $permissionSlug) => $permissionsBySlug[$permissionSlug]?->id)
                    ->filter()
                    ->values()
                    ->all()
            );
        }
    }
}

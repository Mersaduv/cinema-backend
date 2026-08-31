<?php

namespace App\Support;

class AdminPermissions
{
    /**
     * @return array<int, array{name: string, slug: string, description: string}>
     */
    public static function all(): array
    {
        $items = [];

        foreach (self::tree() as $group) {
            foreach ($group['permissions'] as $permission) {
                $items[] = $permission;
            }
        }

        return $items;
    }

    /**
     * @return array<int, string>
     */
    public static function allSlugs(): array
    {
        return array_column(self::all(), 'slug');
    }

    /**
     * @return array<int, array{id: string, label: string, permissions: array<int, array{name: string, slug: string, description: string}>}>
     */
    public static function tree(): array
    {
        return [
            [
                'id' => 'dashboard',
                'label' => 'داشبورد',
                'permissions' => [
                    self::perm('view_dashboard', 'مشاهده داشبورد', 'دسترسی به صفحه اصلی پنل و آمار کلی'),
                ],
            ],
            [
                'id' => 'movies',
                'label' => 'فیلم‌ها',
                'permissions' => [
                    self::perm('view_movies', 'مشاهده فیلم‌ها', 'فهرست و جزئیات فیلم‌ها'),
                    self::perm('create_movies', 'ایجاد فیلم', 'ثبت فیلم جدید'),
                    self::perm('edit_movies', 'ویرایش فیلم', 'ویرایش، انتشار و پردازش فیلم'),
                    self::perm('delete_movies', 'حذف فیلم', 'حذف کامل فیلم'),
                ],
            ],
            [
                'id' => 'series',
                'label' => 'سریال‌ها',
                'permissions' => [
                    self::perm('view_series', 'مشاهده سریال‌ها', 'فهرست و جزئیات سریال‌ها'),
                    self::perm('create_series', 'ایجاد سریال', 'ثبت سریال جدید'),
                    self::perm('edit_series', 'ویرایش سریال', 'ویرایش، انتشار و پردازش سریال'),
                    self::perm('delete_series', 'حذف سریال', 'حذف کامل سریال'),
                ],
            ],
            [
                'id' => 'collections',
                'label' => 'مجموعه‌های فیلم',
                'permissions' => [
                    self::perm('view_collections', 'نمایش مجموعه‌ها', 'مشاهده فهرست مجموعه‌های فیلم (فقط خواندنی)'),
                ],
            ],
            [
                'id' => 'users',
                'label' => 'کاربران پنل',
                'permissions' => [
                    self::perm('view_users', 'مشاهده کاربران', 'فهرست کاربران پنل'),
                    self::perm('create_users', 'ایجاد کاربر', 'ساخت کاربر پنل با نقش و دسترسی'),
                ],
            ],
            [
                'id' => 'viewers',
                'label' => 'بیننده‌ها',
                'permissions' => [
                    self::perm('view_viewers', 'مشاهده بیننده‌ها', 'فهرست حساب‌های بیننده'),
                    self::perm('create_viewers', 'ایجاد بیننده', 'ساخت دستی حساب بیننده'),
                    self::perm('manage_viewers', 'مدیریت بیننده', 'مسدودسازی و فعال‌سازی بیننده'),
                ],
            ],
            [
                'id' => 'comments',
                'label' => 'دیدگاه‌ها',
                'permissions' => [
                    self::perm('view_comments', 'مشاهده دیدگاه‌ها', 'فهرست دیدگاه‌های کاربران'),
                    self::perm('moderate_comments', 'بررسی دیدگاه', 'تایید، رد، ویرایش و سانسور'),
                    self::perm('delete_comments', 'حذف دیدگاه', 'حذف دیدگاه'),
                ],
            ],
            [
                'id' => 'requests',
                'label' => 'درخواست‌ها',
                'permissions' => [
                    self::perm('view_requests', 'مشاهده درخواست‌ها', 'فهرست درخواست محتوا'),
                    self::perm('manage_requests', 'مدیریت درخواست', 'تغییر وضعیت درخواست'),
                ],
            ],
            [
                'id' => 'tickets',
                'label' => 'تیکت‌ها',
                'permissions' => [
                    self::perm('view_tickets', 'مشاهده تیکت‌ها', 'فهرست و جزئیات تیکت'),
                    self::perm('reply_tickets', 'پاسخ به تیکت', 'ارسال پاسخ پشتیبانی'),
                    self::perm('manage_tickets', 'مدیریت تیکت', 'تغییر وضعیت و بستن تیکت'),
                ],
            ],
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function roleDefaults(): array
    {
        $all = self::allSlugs();

        return [
            'admin' => $all,
            'media' => [
                'view_dashboard',
                'view_movies', 'create_movies', 'edit_movies', 'delete_movies',
                'view_series', 'create_series', 'edit_series', 'delete_series',
                'view_collections',
            ],
            'support' => [
                'view_dashboard',
                'view_viewers', 'manage_viewers',
                'view_comments', 'moderate_comments', 'delete_comments',
                'view_requests', 'manage_requests',
                'view_tickets', 'reply_tickets', 'manage_tickets',
            ],
        ];
    }

    /**
     * Obsolete permission slugs and the permission they should map to (if any).
     *
     * @return array<string, string|null>
     */
    public static function obsoleteSlugMap(): array
    {
        return [
            'publish_movies' => 'edit_movies',
            'process_movies' => 'edit_movies',
            'publish_series' => 'edit_series',
            'process_series' => 'edit_series',
            'create_collections' => 'view_collections',
            'edit_collections' => 'view_collections',
            'delete_collections' => 'view_collections',
            'manage_media' => 'view_movies',
            'process_media' => 'edit_movies',
            'manage_users' => 'create_users',
            'create_movie' => 'create_movies',
            'edit_movie' => 'edit_movies',
            'delete_movie' => 'delete_movies',
            'publish_movie' => 'edit_movies',
            'stream_video' => null,
        ];
    }

    /**
     * @return array{name: string, slug: string, description: string}
     */
    private static function perm(string $slug, string $name, string $description): array
    {
        return [
            'slug' => $slug,
            'name' => $name,
            'description' => $description,
        ];
    }
}

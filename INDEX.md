# 📋 ایندکس Backend (Laravel)

این فایل برای شناسایی سریع ساختار Backend توسط Agent ها طراحی شده است.

## 🏗️ ساختار Backend

```
backend/
├── app/
│   ├── Http/
│   │   ├── Controllers/     # کنترلرهای API
│   │   │   └── Controller.php
│   │   └── Middleware/      # میدلورها
│   ├── Models/              # مدل‌های Eloquent
│   │   └── User.php
│   └── Providers/           # سرویس پروایدرها
│       └── AppServiceProvider.php
├── config/                  # فایل‌های تنظیمات
│   ├── app.php
│   ├── database.php         # تنظیمات MySQL
│   └── ...
├── database/
│   ├── migrations/          # مایگریشن‌های دیتابیس
│   ├── seeders/             # سیدرهای دیتابیس
│   └── factories/           # فکتوری‌ها
├── routes/
│   ├── api.php              # مسیرهای API
│   └── web.php              # مسیرهای وب
├── .env                     # تنظیمات محیطی
├── .env.example             # نمونه تنظیمات
└── artisan                  # CLI Laravel
```

## 🔑 فایل‌های کلیدی

### تنظیمات
- `config/database.php` - تنظیمات دیتابیس MySQL
- `config/app.php` - تنظیمات اصلی اپلیکیشن
- `.env` - متغیرهای محیطی (باید از .env.example کپی شود)

### مسیرها
- `routes/api.php` - تمام مسیرهای API در اینجا تعریف می‌شوند
- `routes/web.php` - مسیرهای وب (در صورت نیاز)

### مدل‌ها
- `app/Models/` - تمام مدل‌های Eloquent در اینجا قرار دارند

### کنترلرها
- `app/Http/Controllers/` - کنترلرهای API در اینجا قرار دارند

## 🗄️ دیتابیس

### تنظیمات MySQL در .env:
```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=cinema_db
DB_USERNAME=root
DB_PASSWORD=
```

### مایگریشن‌ها
- `database/migrations/` - تمام مایگریشن‌ها در اینجا هستند
- برای ایجاد مایگریشن جدید: `php artisan make:migration create_table_name`

## 🚀 دستورات مهم

```bash
# ایجاد کنترلر
php artisan make:controller MovieController --api

# ایجاد مدل با مایگریشن
php artisan make:model Movie -m

# ایجاد Resource Controller
php artisan make:controller MovieController --resource

# اجرای مایگریشن
php artisan migrate

# برگرداندن مایگریشن
php artisan migrate:rollback

# اجرای سیدر
php artisan db:seed
```

## 📡 API Structure

API ها به صورت RESTful طراحی شده‌اند:

```
GET    /api/movies           # لیست فیلم‌ها
GET    /api/movies/{id}      # جزئیات فیلم
POST   /api/movies           # ایجاد فیلم جدید
PUT    /api/movies/{id}      # به‌روزرسانی فیلم
DELETE /api/movies/{id}      # حذف فیلم
```

## 🔐 احراز هویت

برای احراز هویت می‌توان از Laravel Sanctum استفاده کرد:
- نصب: `composer require laravel/sanctum`
- پیکربندی: در `config/sanctum.php`

## 📝 نکات مهم

1. تمام API ها در `routes/api.php` تعریف می‌شوند
2. مدل‌ها در `app/Models/` قرار دارند
3. کنترلرها در `app/Http/Controllers/` قرار دارند
4. مایگریشن‌ها در `database/migrations/` قرار دارند
5. زبان پروژه فارسی است اما نام فایل‌ها و متغیرها انگلیسی است

## 🔄 ارتباط با Frontend

- Base URL: `http://localhost:8000`
- API Prefix: `/api`
- CORS: باید در `config/cors.php` تنظیم شود

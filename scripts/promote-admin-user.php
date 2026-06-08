<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

$email = $argv[1] ?? 'admin@example.com';

(new RolePermissionSeeder())->run();

$adminRole = Role::query()->where('slug', 'admin')->firstOrFail();
$allPermissionIds = Permission::query()->pluck('id')->all();
$adminRole->permissions()->sync($allPermissionIds);

$user = User::query()->where('email', $email)->first();

if (!$user) {
    fwrite(STDERR, "User not found: {$email}\n");
    exit(1);
}

$user->forceFill(['status' => 'active'])->save();
$user->roles()->sync([$adminRole->id]);

$user->load('roles.permissions');

echo "User promoted: {$user->email}\n";
echo 'Roles: ' . $user->roles->pluck('slug')->implode(', ') . "\n";
echo 'Permissions: ' . $user->roles
    ->flatMap(fn (Role $role) => $role->permissions->pluck('slug'))
    ->unique()
    ->sort()
    ->implode(', ') . "\n";

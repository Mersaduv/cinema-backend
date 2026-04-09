<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RolePermissionSeeder::class);

        $adminUser = User::updateOrCreate([
            'email' => 'admin@cinema.local',
        ], [
            'name' => 'Cinema Admin',
            'password' => Hash::make('Password123!'),
            'status' => 'active',
        ]);

        $adminRole = Role::where('slug', 'admin')->first();

        if ($adminRole) {
            $adminUser->roles()->sync([$adminRole->id]);
        }
    }
}

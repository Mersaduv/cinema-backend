<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Throwable;

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

        try {
            Artisan::call('cinema:sync-tmdb-reference-data', ['--force' => true]);
        } catch (Throwable) {
            $this->call(TmdbReferenceFallbackSeeder::class);
            $this->call(TmdbCountryFallbackSeeder::class);
        }
    }
}

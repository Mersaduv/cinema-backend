<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Series;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ScheduledPublishMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.timezone' => 'Asia/Kabul']);
    }

    public function test_api_request_publishes_overdue_scheduled_series(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-06 18:05:00', 'Asia/Kabul'));

        $admin = $this->createAdminUser();
        Sanctum::actingAs($admin);

        Series::query()->create([
            'title' => 'Fallout',
            'slug' => 'fallout',
            'publish_status' => 'scheduled',
            'scheduled_for' => Carbon::parse('2026-06-06 18:00:00', 'Asia/Kabul'),
            'metadata' => [],
        ]);

        $this->getJson('/api/admin/series?per_page=12')
            ->assertOk()
            ->assertJsonPath('data.series.0.publish_status', 'published');

        Carbon::setTestNow();
    }

    private function createAdminUser(): User
    {
        $this->seed(RolePermissionSeeder::class);

        $admin = User::factory()->create();
        $adminRole = Role::query()->where('slug', 'admin')->firstOrFail();
        $admin->roles()->attach($adminRole->id);

        return $admin;
    }
}

<?php

namespace Tests\Feature;

use App\Models\ContentRequest;
use App\Models\Role;
use App\Models\User;
use App\Models\Viewer;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContentRequestApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_list_or_create_requests(): void
    {
        $this->getJson('/api/requests')->assertUnauthorized();

        $this->postJson('/api/requests', [
            'title' => 'Interstellar',
            'genre_slug' => 'drama',
            'description' => 'Please add this movie with Persian subtitles.',
        ])->assertUnauthorized();
    }

    public function test_authenticated_user_can_submit_content_request(): void
    {
        $viewer = Viewer::factory()->create(['status' => 'active']);
        Sanctum::actingAs($viewer);

        $response = $this->postJson('/api/requests', [
            'title' => 'Interstellar',
            'genre_slug' => 'drama',
            'description' => 'Please add this movie with Persian subtitles.',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.request.title', 'Interstellar')
            ->assertJsonPath('data.request.genre_name', 'درام')
            ->assertJsonPath('data.request.status', ContentRequest::STATUS_PENDING);

        $this->assertDatabaseHas('content_requests', [
            ContentRequest::ownerColumn() => $viewer->id,
            'title' => 'Interstellar',
            'genre_slug' => 'drama',
            'status' => ContentRequest::STATUS_PENDING,
        ]);
    }

    public function test_authenticated_user_can_list_own_requests(): void
    {
        $viewer = Viewer::factory()->create(['status' => 'active']);
        $other = Viewer::factory()->create(['status' => 'active']);

        ContentRequest::query()->create([
            ContentRequest::ownerColumn() => $viewer->id,
            'title' => 'Mine',
            'genre_slug' => 'drama',
            'genre_name' => 'درام',
            'description' => 'My request description here.',
            'status' => ContentRequest::STATUS_PENDING,
        ]);

        ContentRequest::query()->create([
            ContentRequest::ownerColumn() => $other->id,
            'title' => 'Other',
            'genre_slug' => 'comedy',
            'genre_name' => 'کمدی',
            'description' => 'Someone else request description.',
            'status' => ContentRequest::STATUS_PENDING,
        ]);

        Sanctum::actingAs($viewer);

        $this->getJson('/api/requests')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.requests.0.title', 'Mine');
    }

    public function test_admin_can_list_and_update_requests(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $admin = User::factory()->create(['status' => 'active']);
        $admin->roles()->attach(Role::query()->where('slug', 'admin')->first());

        $request = ContentRequest::query()->create([
            ContentRequest::ownerColumn() => Viewer::factory()->create()->id,
            'title' => 'Dark',
            'genre_slug' => 'mystery',
            'genre_name' => 'معمایی',
            'description' => 'Please add this German series.',
            'status' => ContentRequest::STATUS_PENDING,
        ]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/requests')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.requests.0.title', 'Dark');

        $this->patchJson("/api/admin/requests/{$request->id}", [
            'status' => ContentRequest::STATUS_IN_PROGRESS,
            'admin_note' => 'در حال بررسی هستیم.',
        ])
            ->assertOk()
            ->assertJsonPath('data.request.status', ContentRequest::STATUS_IN_PROGRESS)
            ->assertJsonPath('data.request.admin_note', 'در حال بررسی هستیم.');
    }

    public function test_user_can_filter_requests_by_status(): void
    {
        $viewer = Viewer::factory()->create(['status' => 'active']);

        ContentRequest::query()->create([
            ContentRequest::ownerColumn() => $viewer->id,
            'title' => 'فیلم تست',
            'genre_slug' => 'drama',
            'genre_name' => 'درام',
            'description' => 'توضیحات کافی برای تست درخواست محتوا',
            'status' => ContentRequest::STATUS_PENDING,
        ]);

        ContentRequest::query()->create([
            ContentRequest::ownerColumn() => $viewer->id,
            'title' => 'سریال تست',
            'genre_slug' => 'drama',
            'genre_name' => 'درام',
            'description' => 'توضیحات کافی برای تست درخواست محتوا دوم',
            'status' => ContentRequest::STATUS_FULFILLED,
        ]);

        Sanctum::actingAs($viewer);

        $this->getJson('/api/requests?status=fulfilled')
            ->assertOk()
            ->assertJsonCount(1, 'data.requests')
            ->assertJsonPath('data.requests.0.status', ContentRequest::STATUS_FULFILLED);
    }
}

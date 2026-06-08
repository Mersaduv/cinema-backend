<?php

namespace Tests\Feature;

use App\Models\Movie;
use App\Models\Role;
use App\Models\Series;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CatalogCacheInvalidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cinema.media_root', storage_path('framework/testing-media-root'));
        config()->set('cache.default', 'array');
    }

    public function test_deleted_movie_is_removed_from_admin_list_after_cache_warm(): void
    {
        $admin = $this->createAdminUser();
        Sanctum::actingAs($admin);

        $createResponse = $this->postJson('/api/admin/movies', [
            'original_title' => 'Delete Cache Movie',
            'publish_status' => 'draft',
        ])->assertCreated();

        $movieId = (int) $createResponse->json('data.movie.id');

        $this->getJson('/api/admin/movies?per_page=12')
            ->assertOk()
            ->assertJsonPath('data.movies.0.id', $movieId);

        $this->deleteJson('/api/admin/movies/'.$movieId)->assertOk();

        $this->artisan('cinema:rebuild-catalog-cache', ['--warm' => true])->assertSuccessful();

        $this->getJson('/api/admin/movies?per_page=12')
            ->assertOk()
            ->assertJsonCount(0, 'data.movies');
    }

    public function test_deleted_series_is_removed_from_admin_list_after_cache_warm(): void
    {
        $admin = $this->createAdminUser();
        Sanctum::actingAs($admin);

        $series = Series::query()->create([
            'title' => 'Delete Cache Series',
            'slug' => 'delete-cache-series',
            'publish_status' => 'draft',
            'metadata' => [],
        ]);

        $this->getJson('/api/admin/series?per_page=12')
            ->assertOk()
            ->assertJsonPath('data.series.0.id', $series->id);

        $this->deleteJson('/api/admin/series/'.$series->id)->assertOk();

        $this->artisan('cinema:rebuild-catalog-cache', ['--warm' => true])->assertSuccessful();

        $this->getJson('/api/admin/series?per_page=12')
            ->assertOk()
            ->assertJsonCount(0, 'data.series');
    }

    public function test_catalog_generation_bumps_on_invalidation(): void
    {
        $service = app(\App\Services\Cinema\CatalogCacheService::class);

        $this->assertSame(1, $service->catalogGeneration());

        $service->invalidateImmediately();

        $this->assertSame(2, $service->catalogGeneration());
        $this->assertSame(
            'admin_movies_list_g2_fingerprint',
            $service->listCacheKey('admin_movies_list', 'fingerprint'),
        );
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

<?php

namespace Tests\Feature;

use App\Models\ContentPipeline;
use App\Models\Movie;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContentPipelineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cinema_images.storage_root' => storage_path('framework/testing-image-root'),
        ]);
    }

    public function test_publication_form_with_publish_intent_opens_pipeline(): void
    {
        $admin = $this->createAdminUser();
        Sanctum::actingAs($admin);

        $create = $this->postJson('/api/admin/movies', [
            'title' => 'Pipeline Movie',
            'original_title' => 'Pipeline Movie',
            'slug' => 'pipeline-movie',
            'publish_status' => 'draft',
            'start_processing' => false,
        ]);

        $movieId = $create->json('data.movie.id');

        Http::fake([
            'http://cinema.af/*' => Http::response("#EXTM3U\n#EXT-X-VERSION:3\n", 200),
        ]);

        $response = $this->putJson("/api/admin/movies/{$movieId}/publication-form", [
            'title' => 'Pipeline Movie',
            'slug' => 'pipeline-movie',
            'age_rating' => 'PG-13',
            'hls_url' => 'http://cinema.af/movies/pipeline-movie/hls/master.m3u8',
            'publish_status' => 'published',
        ]);

        $response->assertOk();
        $response->assertJsonPath('pipeline_status', 'pending');

        $this->assertDatabaseHas('content_pipelines', [
            'entity_type' => 'movie',
            'entity_id' => $movieId,
        ]);

        $movie = Movie::query()->findOrFail($movieId);
        $this->assertContains($movie->pipeline_status, ['pending', 'running', 'failed', 'completed']);
        $this->assertContains($movie->publish_status, ['draft', 'published', 'scheduled']);
    }

    public function test_pipeline_endpoint_returns_task_statuses(): void
    {
        $admin = $this->createAdminUser();
        Sanctum::actingAs($admin);

        $movie = Movie::query()->create([
            'title' => 'Pipeline Status Movie',
            'slug' => 'pipeline-status-movie',
            'media_folder' => 'movies/pipeline-status-movie',
            'pipeline_status' => 'running',
            'publish_status' => 'draft',
            'processing_status' => 'pending',
            'metadata' => [],
        ]);

        $pipeline = ContentPipeline::query()->create([
            'entity_type' => 'movie',
            'entity_id' => $movie->id,
            'status' => 'running',
            'triggered_by' => $admin->id,
            'triggered_at' => now(),
            'started_at' => now(),
            'total_tasks' => 2,
            'completed_tasks' => 1,
            'failed_tasks' => 0,
        ]);

        $pipeline->tasks()->create([
            'task_key' => 'image_primary',
            'task_label' => 'پردازش تصویر اصلی',
            'priority' => 1,
            'is_critical' => true,
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        $pipeline->tasks()->create([
            'task_key' => 'hls_validate',
            'task_label' => 'اعتبارسنجی HLS',
            'priority' => 2,
            'is_critical' => true,
            'status' => 'running',
            'started_at' => now(),
        ]);

        $response = $this->getJson("/api/admin/movies/{$movie->id}/pipeline");

        $response->assertOk();
        $response->assertJsonPath('data.pipeline_status', 'running');
        $response->assertJsonPath('data.progress.completed', 1);
        $response->assertJsonPath('data.tasks.0.task_key', 'image_primary');
        $response->assertJsonPath('data.tasks.1.task_key', 'hls_validate');
    }

    private function createAdminUser(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $adminRole = Role::query()->where('slug', 'admin')->firstOrFail();
        $user = User::factory()->create();
        $user->roles()->attach($adminRole->id);

        return $user;
    }
}

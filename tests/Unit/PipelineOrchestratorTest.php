<?php

namespace Tests\Unit;

use App\Models\ContentPipeline;
use App\Models\Movie;
use App\Models\User;
use App\Services\Cinema\PipelineOrchestrator;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PipelineOrchestratorTest extends TestCase
{
    use RefreshDatabase;

    private PipelineOrchestrator $orchestrator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->orchestrator = app(PipelineOrchestrator::class);
    }

    public function test_pipeline_auto_publishes_movie_when_all_tasks_complete(): void
    {
        $admin = User::factory()->create();
        $movie = $this->createMovie([
            'metadata' => [
                'pipeline' => ['intended_publish_status' => 'published'],
            ],
        ]);

        $pipeline = $this->orchestrator->open(
            'movie',
            $movie->id,
            ['image_primary', 'hls_validate'],
            $admin->id,
        );

        $this->orchestrator->taskStarted($pipeline->id, 'image_primary');
        $this->orchestrator->taskCompleted($pipeline->id, 'image_primary');
        $this->orchestrator->taskStarted($pipeline->id, 'hls_validate');
        $this->orchestrator->taskCompleted($pipeline->id, 'hls_validate');

        $movie->refresh();
        $pipeline->refresh();

        $this->assertSame('completed', $pipeline->status);
        $this->assertSame('published', $movie->publish_status);
        $this->assertNotNull($movie->published_at);
    }

    public function test_non_critical_task_failure_does_not_block_pipeline(): void
    {
        $admin = User::factory()->create();
        $movie = $this->createMovie([
            'metadata' => [
                'pipeline' => ['intended_publish_status' => 'published'],
            ],
        ]);

        $pipeline = $this->orchestrator->open(
            'movie',
            $movie->id,
            ['image_primary', 'image_actors'],
            $admin->id,
        );

        $this->orchestrator->taskStarted($pipeline->id, 'image_primary');
        $this->orchestrator->taskCompleted($pipeline->id, 'image_primary');
        $this->orchestrator->taskStarted($pipeline->id, 'image_actors');
        $this->orchestrator->taskFailed($pipeline->id, 'image_actors', 'Actor image failed');

        $movie->refresh();
        $pipeline->refresh();

        $this->assertSame('completed', $pipeline->status);
        $this->assertSame('published', $movie->publish_status);
    }

    public function test_critical_task_failure_fails_pipeline_without_auto_publish(): void
    {
        $admin = User::factory()->create();
        $movie = $this->createMovie([
            'metadata' => [
                'pipeline' => ['intended_publish_status' => 'published'],
            ],
        ]);

        $pipeline = $this->orchestrator->open(
            'movie',
            $movie->id,
            ['image_primary', 'hls_validate'],
            $admin->id,
        );

        $this->orchestrator->taskStarted($pipeline->id, 'image_primary');
        $this->orchestrator->taskCompleted($pipeline->id, 'image_primary');
        $this->orchestrator->taskStarted($pipeline->id, 'hls_validate');
        $this->orchestrator->taskFailed($pipeline->id, 'hls_validate', 'HLS invalid');

        $movie->refresh();
        $pipeline->refresh();

        $this->assertSame('failed', $pipeline->status);
        $this->assertSame('draft', $movie->publish_status);
        $this->assertSame('failed', $movie->pipeline_status);
    }

    public function test_reopen_cancels_previous_running_pipeline(): void
    {
        $admin = User::factory()->create();
        $movie = $this->createMovie();

        $first = $this->orchestrator->open('movie', $movie->id, ['image_primary'], $admin->id);
        $second = $this->orchestrator->open('movie', $movie->id, ['image_primary'], $admin->id);

        $first->refresh();

        $this->assertSame('cancelled', $first->status);
        $this->assertSame('pending', $second->status);
        $this->assertSame(2, ContentPipeline::query()->count());
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createMovie(array $overrides = []): Movie
    {
        $slug = 'pipeline-test-' . uniqid();

        return Movie::query()->create(array_merge([
            'title' => 'Pipeline Test Movie',
            'slug' => $slug,
            'media_folder' => 'movies/' . $slug,
            'publish_status' => 'draft',
            'processing_status' => 'pending',
            'metadata' => [],
        ], $overrides));
    }
}

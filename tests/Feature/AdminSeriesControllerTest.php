<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminSeriesControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_series_store_returns_slim_response_without_episode_objects(): void
    {
        $admin = $this->createAdminUser();
        Sanctum::actingAs($admin);

        $seasons = [];

        for ($season = 1; $season <= 4; $season++) {
            $episodes = [];

            for ($episode = 1; $episode <= 13; $episode++) {
                $episodes[] = [
                    'episode_number' => $episode,
                    'title' => "S{$season}E{$episode}",
                    'source_method' => 'hls',
                ];
            }

            $seasons[] = [
                'season_number' => $season,
                'name' => "Season {$season}",
                'episodes' => $episodes,
            ];
        }

        $startedAt = microtime(true);

        $response = $this->postJson('/api/admin/series', [
            'title' => 'Slim Response Show',
            'slug' => 'slim-response-show',
            'age_rating' => 'TV-14',
            'publish_status' => 'draft',
            'seasons' => $seasons,
        ]);

        $elapsedMs = (microtime(true) - $startedAt) * 1000;

        $response
            ->assertCreated()
            ->assertJsonPath('data.series.title', 'Slim Response Show')
            ->assertJsonPath('data.series.episodes_count', 52)
            ->assertJsonPath('data.series.seasons_count', 4)
            ->assertJsonMissingPath('data.series.seasons');

        $this->assertLessThan(1500, $elapsedMs, "Series store took {$elapsedMs}ms");
    }

    private function createAdminUser(): User
    {
        $this->seed(RolePermissionSeeder::class);

        $user = User::factory()->create([
            'status' => 'active',
        ]);

        $adminRole = Role::query()->where('slug', 'admin')->firstOrFail();
        $user->roles()->sync([$adminRole->id]);

        return $user;
    }
}

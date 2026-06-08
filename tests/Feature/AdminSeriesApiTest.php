<?php

namespace Tests\Feature;

use App\Jobs\SyncSeriesPersonCreditsJob;
use App\Models\Role;
use App\Models\Series;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminSeriesApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_a_series_with_seasons_and_episodes(): void
    {
        $admin = $this->createAdminUser();

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/admin/series', [
            'title' => 'The Last Signal',
            'slug' => 'the-last-signal',
            'original_title' => 'The Last Signal',
            'synopsis' => 'A crew follows a signal through a failing city.',
            'first_air_date' => '2026-05-01',
            'last_air_date' => '2026-05-08',
            'status' => 'Returning Series',
            'show_type' => 'Scripted',
            'episode_runtime_minutes' => 48,
            'network' => 'Cinema Network',
            'poster_url' => 'https://image.tmdb.org/t/p/w780/poster.jpg',
            'backdrop_url' => 'https://image.tmdb.org/t/p/original/backdrop.jpg',
            'trailer_url' => 'https://www.youtube.com/watch?v=abc123',
            'homepage_url' => 'https://example.com/the-last-signal',
            'age_rating' => '16+',
            'catalog_visibility' => 'catalog_search',
            'is_featured' => true,
            'publish_status' => 'published',
            'tmdb_id' => 12345,
            'imdb_id' => 'tt7654321',
            'tvdb_id' => '9876',
            'rating' => 8.4,
            'original_language' => 'en',
            'creators' => ['Creator One'],
            'writers' => ['Writer One'],
            'genres' => ['Drama', 'Sci-Fi'],
            'tags' => ['signal', 'future'],
            'languages' => ['English', 'فارسی'],
            'countries' => ['United States'],
            'actors' => [
                [
                    'name' => 'Actor One',
                    'character' => 'Mina',
                    'profile_url' => 'https://image.tmdb.org/t/p/w300/actor.jpg',
                ],
            ],
            'seasons' => [
                [
                    'season_number' => 1,
                    'name' => 'Season 1',
                    'synopsis' => 'The signal appears.',
                    'air_date' => '2026-05-01',
                    'poster_url' => 'https://image.tmdb.org/t/p/w780/season.jpg',
                    'episodes' => [
                        [
                            'episode_number' => 1,
                            'title' => 'Pilot',
                            'synopsis' => 'The first clue is found.',
                            'air_date' => '2026-05-01',
                            'duration_minutes' => 48,
                            'still_url' => 'https://image.tmdb.org/t/p/w780/still.jpg',
                            'source_method' => 'hls',
                            'hls_url' => 'https://cdn.example.com/series/the-last-signal/s01e01/master.m3u8',
                            'hls_verified' => true,
                            'subtitle_url' => 'https://cdn.example.com/series/the-last-signal/s01e01/fa.vtt',
                            'publish_status' => 'published',
                        ],
                    ],
                ],
            ],
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.series.title', 'The Last Signal')
            ->assertJsonPath('data.series.episodes_count', 1)
            ->assertJsonPath('data.series.seasons_count', 1)
            ->assertJsonMissingPath('data.series.seasons')
            ->assertJsonMissingPath('data.series.metadata');

        $this->assertDatabaseHas('series', [
            'slug' => 'the-last-signal',
            'publish_status' => 'published',
            'is_featured' => true,
        ]);

        $this->assertDatabaseHas('series_episodes', [
            'season_number' => 1,
            'episode_number' => 1,
            'title' => 'Pilot',
        ]);
    }

    public function test_series_store_defers_cast_sync_to_background_job(): void
    {
        Queue::fake();

        $admin = $this->createAdminUser();
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/series', [
            'title' => 'Queued Credits Show',
            'slug' => 'queued-credits-show',
            'publish_status' => 'draft',
            'cast' => [
                [
                    'tmdb_person_id' => 42,
                    'name' => 'Actor Two',
                    'character' => 'Lead',
                    'profile_url' => 'https://image.tmdb.org/t/p/w300/actor2.jpg',
                    'order' => 0,
                ],
            ],
        ])->assertCreated();

        Queue::assertPushed(SyncSeriesPersonCreditsJob::class, function (SyncSeriesPersonCreditsJob $job): bool {
            return $job->seriesId > 0
                && count($job->cast) === 1
                && $job->cast[0]['name'] === 'Actor Two';
        });
    }

    public function test_admin_can_create_series_with_many_episodes_quickly(): void
    {
        $admin = $this->createAdminUser();
        Sanctum::actingAs($admin);

        $seasons = [];
        for ($season = 1; $season <= 3; $season++) {
            $episodes = [];
            for ($episode = 1; $episode <= 12; $episode++) {
                $episodes[] = [
                    'episode_number' => $episode,
                    'title' => "S{$season}E{$episode}",
                    'synopsis_en' => "Overview for season {$season} episode {$episode}.",
                    'publish_status' => 'draft',
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
            'title' => 'Bulk Episode Show',
            'slug' => 'bulk-episode-show',
            'publish_status' => 'draft',
            'seasons' => $seasons,
        ]);

        $elapsedMs = (microtime(true) - $startedAt) * 1000;

        $response
            ->assertCreated()
            ->assertJsonPath('data.series.episodes_count', 36);

        $this->assertLessThan(
            3000,
            $elapsedMs,
            "Expected series create under 3s, took {$elapsedMs}ms",
        );

        $this->assertSame(
            36,
            Series::query()->where('slug', 'bulk-episode-show')->first()?->episodes()->count(),
        );
    }

    public function test_admin_can_list_update_and_delete_series(): void
    {
        $admin = $this->createAdminUser();

        Sanctum::actingAs($admin);

        $createResponse = $this->postJson('/api/admin/series', [
            'title' => 'Editable Show',
            'slug' => 'editable-show',
            'status' => 'Pilot',
            'publish_status' => 'draft',
        ]);

        $seriesId = $createResponse->json('data.series.id');

        $this->getJson('/api/admin/series?search=editable')
            ->assertOk()
            ->assertJsonPath('data.summary.total', 1)
            ->assertJsonPath('data.series.0.slug', 'editable-show');

        $this->putJson("/api/admin/series/{$seriesId}", [
            'title' => 'Editable Show Updated',
            'slug' => 'editable-show-updated',
            'status' => 'Ended',
            'publish_status' => 'draft',
            'seasons' => [
                [
                    'season_number' => 1,
                    'episodes' => [
                        [
                            'episode_number' => 1,
                            'title' => 'Updated Pilot',
                        ],
                    ],
                ],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.series.slug', 'editable-show-updated')
            ->assertJsonMissingPath('data.series.seasons');

        $this->getJson("/api/admin/series/{$seriesId}")
            ->assertOk()
            ->assertJsonPath('data.series.status', 'Ended')
            ->assertJsonPath('data.series.seasons.0.episodes.0.title', 'Updated Pilot');

        $this->deleteJson("/api/admin/series/{$seriesId}")
            ->assertOk()
            ->assertJsonPath('data.deleted_series.slug', 'editable-show-updated');

        $this->assertDatabaseMissing('series', [
            'id' => $seriesId,
        ]);
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

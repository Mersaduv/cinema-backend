<?php

namespace Tests\Unit;

use App\Models\Movie;
use App\Models\User;
use App\Models\WatchProgress;
use App\Services\Cinema\WatchProgressLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WatchProgressLifecycleServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_movie_delete_purges_watch_progress_via_observer(): void
    {
        $user = User::factory()->create();
        $movie = Movie::query()->create([
            'title' => 'Lifecycle Movie',
            'slug' => 'lifecycle-movie',
            'media_folder' => 'movies/lifecycle-movie',
            'publish_status' => 'published',
            'published_at' => now(),
            'metadata' => [],
        ]);

        WatchProgress::query()->create([
            'user_id' => $user->id,
            'media_type' => 'movie',
            'media_id' => $movie->id,
            'episode_key' => 'movie',
            'current_time' => 120,
            'duration' => 3600,
            'percent_watched' => 3.33,
            'is_completed' => false,
            'platform' => 'WEB',
            'last_watched_at' => now(),
        ]);

        $movie->delete();

        $this->assertDatabaseMissing('watch_progress', [
            'media_type' => 'movie',
            'media_id' => $movie->id,
        ]);
    }

    public function test_purge_unplayable_removes_draft_movie_progress(): void
    {
        $user = User::factory()->create();
        $movie = Movie::query()->create([
            'title' => 'Draft Movie',
            'slug' => 'draft-movie',
            'media_folder' => 'movies/draft-movie',
            'publish_status' => 'draft',
            'metadata' => [],
        ]);

        WatchProgress::query()->create([
            'user_id' => $user->id,
            'media_type' => 'movie',
            'media_id' => $movie->id,
            'episode_key' => 'movie',
            'current_time' => 90,
            'duration' => 3600,
            'percent_watched' => 2.5,
            'is_completed' => false,
            'platform' => 'WEB',
            'last_watched_at' => now(),
        ]);

        $removed = app(WatchProgressLifecycleService::class)->purgeUnplayable();

        $this->assertSame(1, $removed);
        $this->assertDatabaseMissing('watch_progress', [
            'media_id' => $movie->id,
        ]);
    }
}

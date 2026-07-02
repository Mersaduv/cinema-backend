<?php

namespace Tests\Feature;

use App\Models\Movie;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PublicMovieApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_movie_payload_exposes_playback_and_subtitle_urls(): void
    {
        $admin = $this->createAdminUser();
        $sourceUrl = 'https://cdn.example.com/sources/public-playback-source.mp4';

        Sanctum::actingAs($admin);

        $createResponse = $this->postJson('/api/admin/movies', [
            'original_title' => 'Public Playback Draft',
            'source_path' => $sourceUrl,
            'start_processing' => false,
        ]);

        $movieId = $createResponse->json('data.movie.id');

        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            $url = $request->url();

            if (str_contains($url, '/movies/public-playback/master.m3u8')) {
                return Http::response(<<<'M3U8'
#EXTM3U
#EXT-X-MEDIA:TYPE=AUDIO,GROUP-ID="audio",NAME="Original Audio",LANGUAGE="eng",AUTOSELECT=YES,DEFAULT=YES,URI="audio/eng/index.m3u8"
#EXT-X-MEDIA:TYPE=AUDIO,GROUP-ID="audio",NAME="Farsi Dub",LANGUAGE="fa",AUTOSELECT=YES,DEFAULT=NO,URI="audio/fa/index.m3u8"
#EXT-X-MEDIA:TYPE=SUBTITLES,GROUP-ID="subs",NAME="Persian",LANGUAGE="fa",AUTOSELECT=YES,DEFAULT=NO,FORCED=NO,URI="subtitles/fa.vtt"
#EXT-X-STREAM-INF:BANDWIDTH=1210000,RESOLUTION=1280x720,CODECS="avc1.64001f,mp4a.40.2",AUDIO="audio",SUBTITLES="subs"
video/720/index.m3u8
M3U8, 200);
            }

            if (str_contains($url, '/movies/public-playback/metadata.json')) {
                return Http::response('', 404);
            }

            return Http::response('Not Found', 404);
        });

        $this->putJson("/api/admin/movies/{$movieId}/publication-form", [
            'title' => 'Public Playback',
            'slug' => 'public-playback',
            'hls_url' => 'https://cdn.example.com/movies/public-playback/master.m3u8',
            'genres' => ['Sci-Fi', 'Adventure'],
            'actors' => [
                [
                    'name' => 'Actor One',
                    'character' => 'Lead',
                    'profile_url' => 'https://image.tmdb.org/t/p/w300/actor-one.jpg',
                ],
            ],
        ])->assertOk();

        Movie::query()->findOrFail($movieId)->update([
            'publish_status' => 'published',
        ]);

        $response = $this->getJson('/api/movies/public-playback');

        $response
            ->assertOk()
            ->assertJsonPath('data.movie.subtitle_source_url', 'https://cdn.example.com/movies/public-playback/subtitles/fa.vtt')
            ->assertJsonPath('data.movie.playback.subtitle_url', 'https://cdn.example.com/movies/public-playback/subtitles/fa.vtt')
            ->assertJsonPath('data.movie.playback.master_playlist_url', 'https://cdn.example.com/movies/public-playback/master.m3u8')
            ->assertJsonPath('data.movie.playback.audio_tracks.0.label', 'زبان اصلی')
            ->assertJsonPath('data.movie.playback.audio_tracks.1.label', 'دوبله فارسی')
            ->assertJsonPath('data.movie.details.genres.0', 'علمی تخیلی')
            ->assertJsonPath('data.movie.details.actors.0.name', 'Actor One');
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

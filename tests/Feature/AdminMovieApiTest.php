<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminMovieApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_register_a_movie_with_a_media_source(): void
    {
        $admin = $this->createAdminUser();
        $sourceUrl = $this->remoteSourceUrl('dune-source.mp4');

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/admin/movies', [
            'title' => 'Dune Part Two',
            'source_path' => $sourceUrl,
            'publish_status' => 'draft',
            'start_processing' => false,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.movie.title', 'Dune Part Two')
            ->assertJsonPath('data.movie.media_asset.source_path', $sourceUrl);

        $this->assertDatabaseHas('movies', [
            'title' => 'Dune Part Two',
            'slug' => 'dune-part-two',
            'processing_status' => 'pending',
        ]);

        $this->assertDatabaseHas('media_assets', [
            'source_path' => $sourceUrl,
            'processing_status' => 'pending',
        ]);
    }

    public function test_admin_can_save_movie_publication_form(): void
    {
        $admin = $this->createAdminUser();
        $sourceUrl = $this->remoteSourceUrl('publication-source.mp4');

        Sanctum::actingAs($admin);

        $createResponse = $this->postJson('/api/admin/movies', [
            'original_title' => 'Original Draft Title',
            'source_path' => $sourceUrl,
            'start_processing' => false,
        ]);

        $movieId = $createResponse->json('data.movie.id');

        Http::fake([
            'http://cinema.af/content2/movies/display-title/master.m3u8' => Http::response('', 200),
            'http://cinema.af/content2/movies/display-title/metadata.json' => Http::response('{}', 404),
        ]);

        $response = $this->putJson("/api/admin/movies/{$movieId}/publication-form", [
            'title' => 'Display Title',
            'slug' => 'display-title',
            'poster_url' => 'https://image.tmdb.org/t/p/w780/poster.jpg',
            'backdrop_url' => 'https://image.tmdb.org/t/p/original/backdrop.jpg',
            'trailer_url' => 'https://www.youtube.com/watch?v=abc123',
            'synopsis' => 'Publication synopsis',
            'age_rating' => 'PG-13',
            'release_year' => 2024,
            'tmdb_id' => 999,
            'imdb_id' => 'tt1234567',
            'tmdb_query' => 'Display Title',
            'rating' => 7.8,
            'original_language' => 'en',
            'runtime_minutes' => 166,
            'directors' => ['Director One', 'Director Two'],
            'writers' => ['Writer One'],
            'production_status' => 'Released',
            'budget' => 160000000,
            'revenue' => 555000000,
            'genres' => ['Sci-Fi', 'Adventure'],
            'tags' => ['mind-bending', 'dream'],
            'languages' => ['English', 'Japanese'],
            'countries' => ['United States of America'],
            'actors' => [
                [
                    'name' => 'Actor One',
                    'character' => 'Lead',
                    'profile_url' => 'https://image.tmdb.org/t/p/w300/actor-one.jpg',
                ],
                [
                    'name' => 'Actor Two',
                    'character' => 'Support',
                    'profile_url' => null,
                ],
            ],
            'hls_url' => 'http://cinema.af/content2/movies/display-title/master.m3u8',
            'subtitle_source_path' => 'https://cdn1.movieland.af/subtitles/display-title/fa.vtt',
            'download_links' => [
                '1080p' => 'http://cinema.af/downloads/display-title-1080p.mp4',
                '480p' => 'http://cinema.af/downloads/display-title-480p.mp4',
            ],
            'cast_members' => ['Actor One', 'Actor Two'],
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.movie.title', 'Display Title')
            ->assertJsonPath('data.movie.age_rating', 'PG-13')
            ->assertJsonPath('data.movie.cast_members.0', 'Actor One')
            ->assertJsonPath('data.movie.processing_status', 'queued')
            ->assertJsonPath('data.movie.metadata.tmdb.tmdb_id', 999)
            ->assertJsonPath('data.movie.metadata.tmdb.original_language', 'en')
            ->assertJsonPath('data.movie.metadata.tmdb.runtime_minutes', 166)
            ->assertJsonPath('data.movie.metadata.tmdb.directors.0', 'Director One')
            ->assertJsonPath('data.movie.metadata.tmdb.writers.0', 'Writer One')
            ->assertJsonPath('data.movie.metadata.tmdb.production_status', 'Released')
            ->assertJsonPath('data.movie.metadata.tmdb.budget', 160000000)
            ->assertJsonPath('data.movie.metadata.tmdb.revenue', 555000000)
            ->assertJsonPath('data.movie.metadata.tmdb.genres.0', 'علمی تخیلی')
            ->assertJsonPath('data.movie.metadata.tmdb.tags.0', 'mind-bending')
            ->assertJsonPath('data.movie.metadata.tmdb.languages.1', 'Japanese')
            ->assertJsonPath('data.movie.metadata.tmdb.countries.0', 'United States of America')
            ->assertJsonPath('data.movie.metadata.tmdb.actors.0.name', 'Actor One')
            ->assertJsonPath('data.movie.metadata.tmdb.actors.0.character', 'Lead')
            ->assertJsonPath(
                'data.movie.metadata.playback.hls_url',
                'http://cinema.af/content2/movies/display-title/master.m3u8'
            )
            ->assertJsonPath('data.movie.subtitle_source_url', 'https://cdn1.movieland.af/subtitles/display-title/fa.vtt');

        $this->assertDatabaseHas('movies', [
            'id' => $movieId,
            'title' => 'Display Title',
            'age_rating' => 'PG-13',
            'processing_status' => 'ready',
        ]);
    }

    public function test_publication_form_syncs_subtitle_and_dubbed_from_hls_manifest(): void
    {
        $admin = $this->createAdminUser();
        $sourceUrl = $this->remoteSourceUrl('manifest-source.mp4');

        Sanctum::actingAs($admin);

        $createResponse = $this->postJson('/api/admin/movies', [
            'original_title' => 'Manifest Draft',
            'source_path' => $sourceUrl,
            'start_processing' => false,
        ]);

        $movieId = $createResponse->json('data.movie.id');

        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            $url = $request->url();

            if (str_contains($url, '/movies/manifest/master.m3u8')) {
                if ($request->method() === 'HEAD') {
                    return Http::response('', 200);
                }

                return Http::response(<<<'M3U8'
#EXTM3U
#EXT-X-MEDIA:TYPE=AUDIO,GROUP-ID="audio",NAME="Original Audio",LANGUAGE="eng",AUTOSELECT=YES,DEFAULT=YES,URI="audio/eng/index.m3u8"
#EXT-X-MEDIA:TYPE=AUDIO,GROUP-ID="audio",NAME="Farsi Dub",LANGUAGE="fa",AUTOSELECT=YES,DEFAULT=NO,URI="audio/fa/index.m3u8"
#EXT-X-MEDIA:TYPE=SUBTITLES,GROUP-ID="subs",NAME="Persian",LANGUAGE="fa",AUTOSELECT=YES,DEFAULT=NO,FORCED=NO,URI="subtitles/fa.vtt"
#EXT-X-STREAM-INF:BANDWIDTH=2223000,RESOLUTION=1920x1080,CODECS="avc1.64001f,mp4a.40.2",AUDIO="audio",SUBTITLES="subs"
video/1080/index.m3u8
#EXT-X-STREAM-INF:BANDWIDTH=1210000,RESOLUTION=1280x720,CODECS="avc1.64001f,mp4a.40.2",AUDIO="audio",SUBTITLES="subs"
video/720/index.m3u8
M3U8, 200);
            }

            if (str_contains($url, '/movies/manifest/metadata.json')) {
                return Http::response('', 404);
            }

            return Http::response('Not Found', 404);
        });

        $response = $this->putJson("/api/admin/movies/{$movieId}/publication-form", [
            'title' => 'Manifest Display',
            'slug' => 'manifest-display',
            'hls_url' => 'https://cdn.example.com/movies/manifest/master.m3u8',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.movie.processing_status', 'queued');

        $this->assertDatabaseHas('movies', [
            'id' => $movieId,
            'has_subtitle' => true,
            'has_dubbed_audio' => true,
            'processing_status' => 'ready',
        ]);
    }

    public function test_publication_form_skips_hls_validation_when_url_unchanged_and_ready(): void
    {
        $admin = $this->createAdminUser();
        Sanctum::actingAs($admin);

        $hlsUrl = 'https://cdn.example.com/stable/master.m3u8';

        $createResponse = $this->postJson('/api/admin/movies', [
            'original_title' => 'Stable Movie',
            'publish_status' => 'draft',
        ]);

        $movieId = $createResponse->json('data.movie.id');

        Http::fake([
            $hlsUrl => Http::response('', 200),
        ]);

        $this->putJson("/api/admin/movies/{$movieId}/publication-form", [
            'title' => 'Stable Movie',
            'slug' => 'stable-movie',
            'hls_url' => $hlsUrl,
            'age_rating' => 'PG-13',
        ])->assertOk();

        $movie = \App\Models\Movie::query()->findOrFail($movieId);
        $movie->update(['processing_status' => 'ready']);
        $movie->mediaAsset()?->update(['processing_status' => 'ready']);

        Http::fake([
            $hlsUrl => Http::response('', 500),
        ]);

        $response = $this->putJson("/api/admin/movies/{$movieId}/publication-form", [
            'title' => 'Stable Movie Updated',
            'slug' => 'stable-movie',
            'hls_url' => $hlsUrl,
            'age_rating' => 'PG-13',
            'synopsis' => 'Only synopsis changed.',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.movie.processing_status', 'ready')
            ->assertJsonPath('data.movie.title', 'Stable Movie Updated');

        $this->assertDatabaseHas('movies', [
            'id' => $movieId,
            'processing_status' => 'ready',
            'title' => 'Stable Movie Updated',
        ]);
    }

    public function test_admin_can_preview_hls_package_endpoint(): void
    {
        $admin = $this->createAdminUser();

        Sanctum::actingAs($admin);

        Http::fake([
            'https://cdn.example.com/pkg/master.m3u8' => Http::response("#EXTM3U\n#EXT-X-VERSION:3\n", 200),
            'https://cdn.example.com/pkg/metadata.json' => Http::response('', 404),
        ]);

        $response = $this->postJson('/api/admin/movies/preview-hls-package', [
            'hls_url' => 'https://cdn.example.com/pkg/master.m3u8',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.canonical_hls_url', 'https://cdn.example.com/pkg/master.m3u8')
            ->assertJsonPath('data.download_links', []);
    }

    public function test_preview_hls_package_coerces_hostname_without_http_scheme(): void
    {
        $admin = $this->createAdminUser();

        Sanctum::actingAs($admin);

        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            $url = $request->url();

            if (str_contains($url, 'cinema.af/content2/beta/2026-05-12/Fallout/season-01/episode-01/master.m3u8')) {
                return Http::response(<<<'M3U8'
#EXTM3U
#EXT-X-MEDIA:TYPE=SUBTITLES,GROUP-ID="subs",NAME="FA",LANGUAGE="fa",URI="subtitles/fa.vtt"
#EXT-X-MEDIA:TYPE=AUDIO,GROUP-ID="audio",NAME="Farsi",LANGUAGE="fa",URI="audio/fa/index.m3u8"
M3U8, 200);
            }

            if (str_contains($url, 'cinema.af/content2/beta/2026-05-12/Fallout/season-01/episode-01/metadata.json')) {
                return Http::response('', 404);
            }

            return Http::response('Not Found', 404);
        });

        $response = $this->postJson('/api/admin/movies/preview-hls-package', [
            'hls_url' => 'cinema.af/content2/beta/2026-05-12/Fallout/season-01/episode-01/master.m3u8',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath(
                'data.canonical_hls_url',
                'http://cinema.af/content2/beta/2026-05-12/Fallout/season-01/episode-01/master.m3u8',
            )
            ->assertJsonPath('data.has_subtitle', true)
            ->assertJsonPath('data.has_dubbed_audio', true);
    }

    public function test_publication_form_normalizes_hls_worker_folder_url_with_spaces(): void
    {
        $admin = $this->createAdminUser();
        $sourceUrl = $this->remoteSourceUrl('folder-url-source.mp4');

        Sanctum::actingAs($admin);

        $createResponse = $this->postJson('/api/admin/movies', [
            'original_title' => 'Folder Url Draft',
            'source_path' => $sourceUrl,
            'start_processing' => false,
        ]);

        $movieId = $createResponse->json('data.movie.id');

        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            $url = $request->url();

            if (str_contains($url, 'Sinners%202025%20Cinema.af/master.m3u8')) {
                return Http::response("#EXTM3U\n#EXT-X-VERSION:3\n", 200);
            }

            if (str_contains($url, 'Sinners%202025%20Cinema.af/metadata.json')) {
                return Http::response(json_encode([
                    'hls_url' => 'master.m3u8',
                    'has_dubbed' => true,
                    'has_subtitle' => true,
                    'qualities' => ['1080'],
                    'audio_tracks' => [
                        [
                            'name' => 'Original',
                            'language' => 'en',
                            'type' => 'original',
                            'uri' => 'audio/en/index.m3u8',
                        ],
                        [
                            'name' => 'Farsi',
                            'language' => 'fa',
                            'type' => 'dubbed',
                            'uri' => 'audio/fa/index.m3u8',
                        ],
                    ],
                    'subtitles' => [
                        [
                            'name' => 'FA',
                            'language' => 'fa',
                            'uri' => 'subtitles/fa.vtt',
                        ],
                    ],
                ], JSON_THROW_ON_ERROR), 200);
            }

            return Http::response('Not Found', 404);
        });

        $folderUrl = 'http://cinema.af/content2/beta/2026-05-09/Sinners 2025 Cinema.af';

        $response = $this->putJson("/api/admin/movies/{$movieId}/publication-form", [
            'title' => 'Sinners Folder',
            'slug' => 'sinners-folder',
            'hls_url' => $folderUrl,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.movie.processing_status', 'queued')
            ->assertJsonPath(
                'data.movie.metadata.playback.hls_url',
                'http://cinema.af/content2/beta/2026-05-09/Sinners%202025%20Cinema.af/master.m3u8'
            );

        $this->assertDatabaseHas('movies', [
            'id' => $movieId,
            'has_subtitle' => true,
            'has_dubbed_audio' => true,
            'processing_status' => 'ready',
        ]);
    }

    public function test_queue_processing_endpoint_reports_removed_logic(): void
    {
        $admin = $this->createAdminUser();
        $sourceUrl = $this->remoteSourceUrl('arrival-source.mp4');

        Sanctum::actingAs($admin);

        $createResponse = $this->postJson('/api/admin/movies', [
            'title' => 'Arrival',
            'source_path' => $sourceUrl,
            'publish_status' => 'draft',
            'start_processing' => false,
        ]);

        $movieId = $createResponse->json('data.movie.id');

        $response = $this->postJson("/api/admin/movies/{$movieId}/process");

        $response
            ->assertStatus(410)
            ->assertJsonPath('message', 'Internal HLS processing has been removed from this project.');
    }

    public function test_stop_processing_endpoint_reports_removed_logic(): void
    {
        $admin = $this->createAdminUser();
        $sourceUrl = $this->remoteSourceUrl('stop-source.mp4');

        Sanctum::actingAs($admin);

        $createResponse = $this->postJson('/api/admin/movies', [
            'title' => 'Stop Queue Movie',
            'source_path' => $sourceUrl,
            'start_processing' => false,
        ]);

        $movieId = $createResponse->json('data.movie.id');

        $response = $this->postJson("/api/admin/movies/{$movieId}/stop-processing");

        $response
            ->assertStatus(410)
            ->assertJsonPath('message', 'Internal HLS processing has been removed from this project.');
    }

    public function test_deleting_movie_removes_movie_record(): void
    {
        $admin = $this->createAdminUser();
        $sourceUrl = $this->remoteSourceUrl('delete-dispose-source.mp4');

        Sanctum::actingAs($admin);

        $createResponse = $this->postJson('/api/admin/movies', [
            'title' => 'Delete Dispose Movie',
            'source_path' => $sourceUrl,
            'start_processing' => false,
        ]);

        $movieId = $createResponse->json('data.movie.id');

        $response = $this->deleteJson("/api/admin/movies/{$movieId}");

        $response->assertOk();

        $this->assertDatabaseMissing('movies', [
            'id' => $movieId,
        ]);
    }

    public function test_admin_can_update_movie_draft_form(): void
    {
        $admin = $this->createAdminUser();
        $sourceUrl = $this->remoteSourceUrl('draft-update-source.mp4');
        $updatedSourceUrl = $this->remoteSourceUrl('draft-update-source-2.mp4');

        Sanctum::actingAs($admin);

        $createResponse = $this->postJson('/api/admin/movies', [
            'original_title' => 'Draft Original',
            'source_path' => $sourceUrl,
            'start_processing' => false,
        ]);

        $movieId = $createResponse->json('data.movie.id');

        $response = $this->putJson("/api/admin/movies/{$movieId}/draft-form", [
            'title' => 'Draft Display',
            'original_title' => 'Draft Original Updated',
            'slug' => 'draft-display-updated',
            'release_year' => 2024,
            'provider_name' => 'Cinema Provider',
            'source_path' => $updatedSourceUrl,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.movie.slug', 'draft-display-updated')
            ->assertJsonPath('data.movie.provider_name', 'Cinema Provider');

        $this->assertDatabaseHas('movies', [
            'id' => $movieId,
            'slug' => 'draft-display-updated',
            'provider_name' => 'Cinema Provider',
        ]);
    }

    public function test_ready_movie_can_be_published_by_admin(): void
    {
        $admin = $this->createAdminUser();
        $sourceUrl = $this->remoteSourceUrl('published-source.mp4');

        Sanctum::actingAs($admin);

        $createResponse = $this->postJson('/api/admin/movies', [
            'title' => 'Published Movie',
            'source_path' => $sourceUrl,
            'publish_status' => 'draft',
            'start_processing' => false,
        ]);

        $movieId = $createResponse->json('data.movie.id');

        $movie = \App\Models\Movie::query()->findOrFail($movieId);
        $movie->update(['processing_status' => 'ready']);
        $movie->mediaAsset()->update([
            'processing_status' => 'ready',
        ]);

        $response = $this->putJson("/api/admin/movies/{$movieId}/publish-status", [
            'publish_status' => 'published',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.movie.publish_status', 'published');

        $this->assertDatabaseHas('movies', [
            'id' => $movieId,
            'publish_status' => 'published',
        ]);
    }

    public function test_admin_cannot_register_a_movie_with_uploaded_source_file(): void
    {
        $admin = $this->createAdminUser();

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/admin/movies', [
            'title' => 'Uploaded Movie',
            'publish_status' => 'draft',
            'start_processing' => false,
            'source_file' => UploadedFile::fake()->create('uploaded-movie.mp4', 256, 'video/mp4'),
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonPath('errors.source_file.0', 'Local source file upload is not supported. Provide an HTTP(S) source URL instead.');
    }

    public function test_admin_cannot_register_a_movie_with_local_disk_source_path(): void
    {
        $admin = $this->createAdminUser();

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/admin/movies', [
            'title' => 'Local Path Movie',
            'source_path' => 'D:\\content\\movies\\local-source.mp4',
            'publish_status' => 'draft',
        ]);

        $response->assertStatus(422);
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

    private function remoteSourceUrl(string $name): string
    {
        return 'https://cdn.example.com/sources/' . rawurlencode($name);
    }
}

<?php

namespace Tests\Unit;

use App\Services\Cinema\PlaybackSessionService;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlaybackSessionServiceTest extends TestCase
{
    #[Test]
    public function verify_token_returns_true_for_valid_hmac(): void
    {
        config(['cinema.playback_signing_secret' => 'test-secret-32-characters-long!!']);

        $service = app(PlaybackSessionService::class);
        $expires = Carbon::createFromTimestamp(4_000_000_000);
        $baseUrl = 'https://cdn.example.com/movies/slug/master.m3u8';
        $signature = hash_hmac('sha256', $baseUrl.'|'.$expires->getTimestamp(), 'test-secret-32-characters-long!!');
        $signedUrl = $baseUrl.'?cinema_exp='.$expires->getTimestamp().'&cinema_sig='.$signature;

        Carbon::setTestNow(Carbon::createFromTimestamp(3_999_999_000));

        $this->assertTrue($service->verifyToken($signedUrl));
    }

    #[Test]
    public function verify_token_returns_false_for_expired_token(): void
    {
        config(['cinema.playback_signing_secret' => 'test-secret-32-characters-long!!']);

        $service = app(PlaybackSessionService::class);
        $expires = Carbon::createFromTimestamp(1_000_000_000);
        $baseUrl = 'https://cdn.example.com/movies/slug/master.m3u8';
        $signature = hash_hmac('sha256', $baseUrl.'|'.$expires->getTimestamp(), 'test-secret-32-characters-long!!');
        $signedUrl = $baseUrl.'?cinema_exp='.$expires->getTimestamp().'&cinema_sig='.$signature;

        Carbon::setTestNow(Carbon::createFromTimestamp(2_000_000_000));

        $this->assertFalse($service->verifyToken($signedUrl));
    }

    #[Test]
    public function verify_token_returns_false_for_tampered_token(): void
    {
        config(['cinema.playback_signing_secret' => 'test-secret-32-characters-long!!']);

        $service = app(PlaybackSessionService::class);
        $expires = Carbon::createFromTimestamp(4_000_000_000);
        $signedUrl = 'https://cdn.example.com/movies/slug/master.m3u8?cinema_exp='.$expires->getTimestamp().'&cinema_sig=deadbeef';

        Carbon::setTestNow(Carbon::createFromTimestamp(3_999_999_000));

        $this->assertFalse($service->verifyToken($signedUrl));
    }

    #[Test]
    public function issue_for_movie_signs_progressive_url(): void
    {
        config(['cinema.playback_signing_secret' => '']);

        $movie = new \App\Models\Movie();
        $movie->metadata = [
            'playback' => [
                'progressive_url' => 'https://cdn.example.com/movies/a.mp4',
            ],
        ];

        $session = app(PlaybackSessionService::class)->issueForMovie($movie);

        $this->assertNull($session['hls_url']);
        $this->assertSame('https://cdn.example.com/movies/a.mp4', $session['progressive_url']);
    }

    #[Test]
    public function issue_for_episode_uses_video_url_when_source_is_mp4(): void
    {
        config(['cinema.playback_signing_secret' => '']);

        $series = new \App\Models\Series();
        $series->metadata = [];
        $episode = new \App\Models\SeriesEpisode();
        $episode->source_method = 'url';
        $episode->video_url = 'https://cdn.example.com/series/s01e01.mp4';

        $session = app(PlaybackSessionService::class)->issueForEpisode($episode, $series);

        $this->assertNull($session['hls_url']);
        $this->assertSame('https://cdn.example.com/series/s01e01.mp4', $session['progressive_url']);
    }
}

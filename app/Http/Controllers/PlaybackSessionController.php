<?php

namespace App\Http\Controllers;

use App\Models\Movie;
use App\Models\Series;
use App\Models\SeriesEpisode;
use App\Services\Cinema\PlaybackSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PlaybackSessionController extends Controller
{
    public function __construct(
        private readonly PlaybackSessionService $playbackSessionService,
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        $movieSlug = trim((string) $request->query('movie_slug', ''));
        $seriesSlug = trim((string) $request->query('series_slug', ''));
        $season = (int) $request->query('season', 0);
        $episode = (int) $request->query('episode', 0);

        if ($movieSlug !== '') {
            $movie = Movie::query()
                ->where('slug', $movieSlug)
                ->where('publish_status', 'published')
                ->first();

            if (! $movie) {
                return response()->json(['success' => false, 'message' => 'Movie not found.'], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $this->playbackSessionService->issueForMovie($movie),
            ]);
        }

        if ($seriesSlug !== '' && $season > 0 && $episode > 0) {
            $series = Series::query()
                ->where('slug', $seriesSlug)
                ->where('publish_status', 'published')
                ->first();

            if (! $series) {
                return response()->json(['success' => false, 'message' => 'Series not found.'], 404);
            }

            $episodeModel = SeriesEpisode::query()
                ->where('series_id', $series->id)
                ->where('season_number', $season)
                ->where('episode_number', $episode)
                ->first();

            if (! $episodeModel || ! $this->playbackSessionService->episodeHasPlayableSource($episodeModel)) {
                return response()->json(['success' => false, 'message' => 'Episode not found.'], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $this->playbackSessionService->issueForEpisode($episodeModel, $series),
            ]);
        }

        return response()->json(['success' => false, 'message' => 'Invalid playback session request.'], 422);
    }

    public function verifyToken(Request $request): Response
    {
        $url = (string) $request->query('url', '');
        $isValid = $this->playbackSessionService->verifyToken($url);

        return response('', $isValid ? 200 : 403);
    }
}

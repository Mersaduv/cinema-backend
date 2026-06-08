<?php

namespace App\Http\Controllers;

use App\Models\Movie;
use App\Models\Series;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $query = trim((string) $request->string('q'));
        $type = strtolower(trim((string) $request->string('type', 'all')));
        $limit = min(max((int) $request->integer('limit', 10), 1), 30);

        if ($query === '') {
            return response()->json([
                'success' => true,
                'data' => ['movies' => [], 'series' => []],
            ]);
        }

        $term = '%'.$query.'%';
        $movies = [];
        $series = [];

        if ($type === 'all' || $type === 'movie') {
            $movies = Movie::query()
                ->published()
                ->with(['genres'])
                ->where(function ($builder) use ($term): void {
                    $builder
                        ->where('title', 'like', $term)
                        ->orWhere('original_title', 'like', $term)
                        ->orWhere('synopsis', 'like', $term);
                })
                ->limit($limit)
                ->get()
                ->map(fn (Movie $movie) => [
                    'id' => $movie->id,
                    'title' => $movie->title,
                    'slug' => $movie->slug,
                    'release_year' => $movie->release_year,
                    'type' => 'movie',
                ])
                ->all();
        }

        if ($type === 'all' || $type === 'series') {
            $series = Series::query()
                ->published()
                ->with(['genres'])
                ->where(function ($builder) use ($term): void {
                    $builder
                        ->where('title', 'like', $term)
                        ->orWhere('original_title', 'like', $term)
                        ->orWhere('synopsis', 'like', $term);
                })
                ->limit($limit)
                ->get()
                ->map(fn (Series $item) => [
                    'id' => $item->id,
                    'title' => $item->title,
                    'slug' => $item->slug,
                    'release_year' => $item->release_year,
                    'type' => 'series',
                ])
                ->all();
        }

        return response()->json([
            'success' => true,
            'data' => [
                'movies' => $movies,
                'series' => $series,
            ],
        ]);
    }
}

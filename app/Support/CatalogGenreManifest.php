<?php

namespace App\Support;

use App\Models\Genre;

/**
 * Full VOD-style genre panel: TMDB movie + TV + platform labels (like MOVIELAND / Filimo catalogs).
 * One ordered list for both /movies and /series filter UIs.
 */
class CatalogGenreManifest
{
    /**
     * Display order for catalog filters. tmdb_id null = cinema-only tag (still filterable when tagged on content).
     *
     * @var array<int, array{slug: string, name: string, tmdb_id: int|null, type: string}>
     */
    public const CATALOG_ROWS = [
        ['slug' => 'action', 'name' => 'اکشن', 'tmdb_id' => 28, 'type' => 'both'],
        ['slug' => 'comedy', 'name' => 'کمدی', 'tmdb_id' => 35, 'type' => 'both'],
        ['slug' => 'horror', 'name' => 'ترسناک', 'tmdb_id' => 27, 'type' => 'both'],
        ['slug' => 'fantasy', 'name' => 'فانتزی', 'tmdb_id' => 14, 'type' => 'both'],
        ['slug' => 'drama', 'name' => 'درام', 'tmdb_id' => 18, 'type' => 'both'],
        ['slug' => 'science-fiction', 'name' => 'علمی تخیلی', 'tmdb_id' => 878, 'type' => 'both'],
        ['slug' => 'mystery', 'name' => 'معمایی', 'tmdb_id' => 9648, 'type' => 'both'],
        ['slug' => 'romance', 'name' => 'عاشقانه', 'tmdb_id' => 10749, 'type' => 'both'],
        ['slug' => 'adventure', 'name' => 'ماجراجویی', 'tmdb_id' => 12, 'type' => 'both'],
        ['slug' => 'thriller', 'name' => 'هیجان‌انگیز', 'tmdb_id' => 53, 'type' => 'both'],
        ['slug' => 'history', 'name' => 'تاریخی', 'tmdb_id' => 36, 'type' => 'both'],
        ['slug' => 'war', 'name' => 'جنگی', 'tmdb_id' => 10752, 'type' => 'both'],
        ['slug' => 'documentary', 'name' => 'مستند', 'tmdb_id' => 99, 'type' => 'both'],
        ['slug' => 'classic', 'name' => 'کلاسیک', 'tmdb_id' => null, 'type' => 'both'],
        ['slug' => 'crime', 'name' => 'جنایی', 'tmdb_id' => 80, 'type' => 'both'],
        ['slug' => 'family', 'name' => 'خانوادگی', 'tmdb_id' => 10751, 'type' => 'both'],
        ['slug' => 'education-entertainment', 'name' => 'آموزش و سرگرمی', 'tmdb_id' => null, 'type' => 'both'],
        ['slug' => 'anime-animation', 'name' => 'انیمه و انیمیشن', 'tmdb_id' => null, 'type' => 'both'],
        ['slug' => 'talk-show', 'name' => 'گفت‌وگو', 'tmdb_id' => 10767, 'type' => 'tv'],
        ['slug' => 'western', 'name' => 'وسترن', 'tmdb_id' => 37, 'type' => 'both'],
        ['slug' => 'music', 'name' => 'موزیک', 'tmdb_id' => 10402, 'type' => 'both'],
        ['slug' => 'biography', 'name' => 'زندگی‌نامه', 'tmdb_id' => null, 'type' => 'both'],
        ['slug' => 'war-politics', 'name' => 'جنگ و سیاست', 'tmdb_id' => 10768, 'type' => 'tv'],
        ['slug' => 'animation', 'name' => 'انیمیشن', 'tmdb_id' => 16, 'type' => 'both'],
        ['slug' => 'cartoon', 'name' => 'کارتون', 'tmdb_id' => null, 'type' => 'both'],
        ['slug' => 'noir-mystery', 'name' => 'رازآلود', 'tmdb_id' => null, 'type' => 'both'],
        ['slug' => 'tv-movie', 'name' => 'فیلم تلویزیونی', 'tmdb_id' => 10770, 'type' => 'movie'],
        ['slug' => 'action-adventure', 'name' => 'اکشن و ماجراجویی', 'tmdb_id' => 10759, 'type' => 'tv'],
        ['slug' => 'kids', 'name' => 'کودکان', 'tmdb_id' => 10762, 'type' => 'tv'],
        ['slug' => 'news', 'name' => 'اخبار', 'tmdb_id' => 10763, 'type' => 'tv'],
        ['slug' => 'reality', 'name' => 'واقعی', 'tmdb_id' => 10764, 'type' => 'tv'],
        ['slug' => 'sci-fi-fantasy', 'name' => 'علمی‌تخیلی و فانتزی', 'tmdb_id' => 10765, 'type' => 'tv'],
        ['slug' => 'soap', 'name' => 'داستان شبانی', 'tmdb_id' => 10766, 'type' => 'tv'],
        ['slug' => 'short', 'name' => 'کوتاه', 'tmdb_id' => null, 'type' => 'both'],
        ['slug' => 'anime', 'name' => 'انیمه', 'tmdb_id' => null, 'type' => 'tv'],
    ];

    public static function ensureCatalogGenresExist(): void
    {
        foreach (self::CATALOG_ROWS as $index => $row) {
            self::upsertManifestRow($row, $index + 1);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    /** English labels covered by a Persian manifest row — never expose in filter API. */
    private const SUPPRESSED_ENGLISH_NAMES = [
        'Drama',
        'Action & Adventure',
        'Sci-Fi & Fantasy',
        'Science Fiction',
        'Action',
        'Comedy',
        'Horror',
        'Fantasy',
        'Romance',
        'Adventure',
        'Thriller',
        'Documentary',
        'Animation',
    ];

    public static function orderedForApi(string $kind): array
    {
        self::ensureCatalogGenresExist();

        $table = $kind === 'series' ? 'series_genre' : 'movie_genre';
        $contentTable = $kind === 'series' ? 'series' : 'movies';
        $fk = $kind === 'series' ? 'series_id' : 'movie_id';

        $seenSlugs = [];
        $seenTmdbIds = [];

        return collect(self::CATALOG_ROWS)
            ->map(function (array $row, int $index) use (&$seenSlugs, &$seenTmdbIds, $table, $contentTable, $fk) {
                $genre = self::resolveManifestGenre($row);
                if (! $genre) {
                    return null;
                }

                if (isset($seenSlugs[$genre->slug])) {
                    return null;
                }
                if ($genre->tmdb_id !== null && isset($seenTmdbIds[$genre->tmdb_id])) {
                    return null;
                }

                $seenSlugs[$genre->slug] = true;
                if ($genre->tmdb_id !== null) {
                    $seenTmdbIds[$genre->tmdb_id] = true;
                }

                $count = \Illuminate\Support\Facades\DB::table($table)
                    ->join($contentTable, "{$contentTable}.id", '=', "{$table}.{$fk}")
                    ->where("{$table}.genre_id", $genre->id)
                    ->where("{$contentTable}.publish_status", 'published')
                    ->count();

                $label = $row['name'];
                if (in_array($label, self::SUPPRESSED_ENGLISH_NAMES, true)) {
                    return null;
                }
                if (in_array($genre->name, self::SUPPRESSED_ENGLISH_NAMES, true)) {
                    return null;
                }

                return [
                    'id' => $genre->id,
                    'tmdb_id' => $genre->tmdb_id,
                    'name' => $label,
                    'slug' => $genre->slug,
                    'count' => $count,
                    'display_order' => $index + 1,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array{slug: string, name: string, tmdb_id: int|null, type: string}  $row
     */
    private static function resolveManifestGenre(array $row): ?Genre
    {
        if ($row['tmdb_id'] !== null) {
            $byTmdb = Genre::query()->where('tmdb_id', $row['tmdb_id'])->first();
            if ($byTmdb) {
                return $byTmdb;
            }
        }

        return Genre::query()->where('slug', $row['slug'])->first();
    }

    /**
     * @param  array{slug: string, name: string, tmdb_id: int|null, type: string}  $row
     */
    private static function upsertManifestRow(array $row, int $order): void
    {
        $slug = $row['slug'];
        $tmdbId = $row['tmdb_id'];

        $existing = $tmdbId !== null
            ? Genre::query()->where('tmdb_id', $tmdbId)->first()
            : Genre::query()->where('slug', $slug)->first();

        if ($existing) {
            $resolvedSlug = self::resolveUniqueSlug($slug, $existing->id);
            $existing->update([
                'name' => $row['name'],
                'slug' => $resolvedSlug,
                'type' => self::mergeType($existing->type, $row['type']),
                'display_order' => $order,
            ]);

            return;
        }

        $bySlug = Genre::query()->where('slug', $slug)->first();
        if ($bySlug) {
            $bySlug->update([
                'name' => $row['name'],
                'tmdb_id' => $tmdbId ?? $bySlug->tmdb_id,
                'type' => self::mergeType($bySlug->type, $row['type']),
                'display_order' => $order,
            ]);

            return;
        }

        Genre::query()->create([
            'tmdb_id' => $tmdbId,
            'name' => $row['name'],
            'slug' => $slug,
            'type' => $row['type'] === 'both' ? 'tv' : $row['type'],
            'display_order' => $order,
        ]);
    }

    private static function resolveUniqueSlug(string $desired, int $exceptId): string
    {
        $slug = $desired;
        $counter = 2;

        while (
            Genre::query()
                ->where('slug', $slug)
                ->where('id', '!=', $exceptId)
                ->exists()
        ) {
            $slug = $desired.'-'.$counter;
            $counter++;
        }

        return $slug;
    }

    private static function mergeType(string $existing, string $incoming): string
    {
        if ($existing === 'both' || $incoming === 'both' || $existing === $incoming) {
            return 'both';
        }

        return 'both';
    }
}

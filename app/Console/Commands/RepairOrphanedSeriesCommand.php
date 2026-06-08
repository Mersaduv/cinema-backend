<?php

namespace App\Console\Commands;

use App\Models\Series;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RepairOrphanedSeriesCommand extends Command
{
    protected $signature = 'cinema:repair-orphaned-series {--dry-run : Report only, do not write}';

    protected $description = 'Recreate missing series rows referenced by seasons/episodes (fixes empty admin lists after broken deletes)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $orphanSeriesIds = DB::table('series_seasons')
            ->select('series_id')
            ->distinct()
            ->pluck('series_id')
            ->merge(
                DB::table('series_episodes')
                    ->select('series_id')
                    ->distinct()
                    ->pluck('series_id'),
            )
            ->unique()
            ->filter(fn ($id) => (int) $id > 0)
            ->values();

        $existingIds = Series::query()->whereIn('id', $orphanSeriesIds)->pluck('id');
        $missingIds = $orphanSeriesIds->diff($existingIds)->values();

        if ($missingIds->isEmpty()) {
            $this->info('No orphaned series seasons/episodes found.');

            return self::SUCCESS;
        }

        foreach ($missingIds as $seriesId) {
            $seriesId = (int) $seriesId;
            $seasonCount = (int) DB::table('series_seasons')->where('series_id', $seriesId)->count();
            $episodeCount = (int) DB::table('series_episodes')->where('series_id', $seriesId)->count();
            $slug = $this->inferSlugFromEpisodes($seriesId) ?? ('recovered-series-' . $seriesId);
            $title = Str::title(str_replace('-', ' ', $slug));

            $this->line("Orphan series_id={$seriesId}: slug={$slug}, seasons={$seasonCount}, episodes={$episodeCount}");

            if ($dryRun) {
                continue;
            }

            if (Series::query()->where('slug', $slug)->exists()) {
                $slug = $slug . '-' . $seriesId;
            }

            $now = now();

            DB::table('series')->insert([
                'id' => $seriesId,
                'title' => $title,
                'slug' => $slug,
                'original_title' => $title,
                'synopsis' => null,
                'first_air_date' => null,
                'last_air_date' => null,
                'release_year' => null,
                'status' => null,
                'show_type' => null,
                'episode_runtime_minutes' => null,
                'number_of_seasons' => $seasonCount ?: null,
                'number_of_episodes' => $episodeCount ?: null,
                'has_subtitle' => DB::table('series_episodes')
                    ->where('series_id', $seriesId)
                    ->whereNotNull('subtitle_url')
                    ->where('subtitle_url', '!=', '')
                    ->exists(),
                'has_dubbed' => DB::table('series_episodes')
                    ->where('series_id', $seriesId)
                    ->whereNotNull('dubbed_audio_url')
                    ->where('dubbed_audio_url', '!=', '')
                    ->exists(),
                'network' => null,
                'provider_name' => null,
                'poster_url' => null,
                'backdrop_url' => null,
                'trailer_url' => null,
                'age_rating' => null,
                'catalog_visibility' => 'catalog_search',
                'is_featured' => false,
                'publish_status' => 'draft',
                'scheduled_for' => null,
                'published_at' => null,
                'metadata' => json_encode([
                    'recovered' => [
                        'source' => 'cinema:repair-orphaned-series',
                        'recovered_at' => $now->toIso8601String(),
                    ],
                ], JSON_UNESCAPED_UNICODE),
                'created_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->info("  Recreated series #{$seriesId} ({$slug}).");
        }

        if (!$dryRun) {
            $this->info('Done. Refresh the admin series list.');
        }

        return self::SUCCESS;
    }

    private function inferSlugFromEpisodes(int $seriesId): ?string
    {
        $episodeSlug = DB::table('series_episodes')
            ->where('series_id', $seriesId)
            ->orderBy('id')
            ->value('slug');

        if (!is_string($episodeSlug) || $episodeSlug === '') {
            return null;
        }

        if (preg_match('/^(.+)-s\d+-e\d+/i', $episodeSlug, $matches) === 1) {
            return trim($matches[1], '-');
        }

        return null;
    }
}

<?php

namespace App\Console\Commands;

use App\Models\Movie;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditCastConsistencyCommand extends Command
{
    protected $signature = 'cinema:audit-cast-consistency';

    protected $description = 'Report movies where movie_cast row count differs from cast_members JSON length';

    public function handle(): int
    {
        $mismatches = Movie::query()
            ->select(['movies.id', 'movies.title', 'movies.slug', 'movies.cast_members'])
            ->selectSub(
                DB::table('movie_cast')->selectRaw('count(*)')->whereColumn('movie_cast.movie_id', 'movies.id'),
                'cast_rows_count',
            )
            ->get()
            ->filter(function (Movie $movie) {
                $jsonCount = is_array($movie->cast_members) ? count($movie->cast_members) : 0;
                $rowCount = (int) ($movie->getAttribute('cast_rows_count') ?? 0);

                return $jsonCount !== $rowCount;
            });

        if ($mismatches->isEmpty()) {
            $this->info('All movies have consistent cast_members JSON and movie_cast rows.');

            return self::SUCCESS;
        }

        $this->warn(sprintf('Found %d movies with cast inconsistencies:', $mismatches->count()));

        foreach ($mismatches as $movie) {
            $jsonCount = is_array($movie->cast_members) ? count($movie->cast_members) : 0;
            $rowCount = (int) ($movie->getAttribute('cast_rows_count') ?? 0);

            $this->line(sprintf(
                '- #%d %s (%s): json=%d rows=%d',
                $movie->id,
                $movie->title,
                $movie->slug,
                $jsonCount,
                $rowCount,
            ));
        }

        return self::FAILURE;
    }
}

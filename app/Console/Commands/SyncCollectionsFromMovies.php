<?php

namespace App\Console\Commands;

use App\Models\Movie;
use App\Services\Cinema\CollectionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class SyncCollectionsFromMovies extends Command
{
    protected $signature = 'cinema:sync-collections
        {--movie-id= : Sync a single movie by local ID}
        {--only-published : Only movies with publish_status=published}';

    protected $description = 'Auto-sync TMDB collections from movies (belongs_to_collection + collection parts)';

    public function handle(CollectionService $collectionService): int
    {
        if (! Schema::hasTable('collections')) {
            $this->error('Run migrations first: php artisan migrate');

            return self::FAILURE;
        }

        $movieId = $this->option('movie-id');
        $query = Movie::query()->whereNotNull('tmdb_id');

        if ($movieId) {
            $query->whereKey((int) $movieId);
        }

        if ($this->option('only-published')) {
            $query->where('publish_status', 'published');
        }

        $total = (clone $query)->count();
        if ($total === 0) {
            $this->warn('No movies with tmdb_id found.');

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $synced = 0;

        $query->orderBy('id')->chunkById(25, function ($movies) use ($collectionService, $bar, &$synced): void {
            foreach ($movies as $movie) {
                $metadata = is_array($movie->metadata) ? $movie->metadata : [];
                $collectionService->syncFromMoviePublication(
                    $movie,
                    $metadata,
                    (int) $movie->tmdb_id,
                );
                $synced++;
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);
        $this->info("Processed {$synced} movie(s). Collections linked where TMDB provides belongs_to_collection.");

        return self::SUCCESS;
    }
}

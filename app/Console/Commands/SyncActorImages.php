<?php

namespace App\Console\Commands;

use App\Models\Actor;
use App\Services\Cinema\ActorImageSyncService;
use Illuminate\Console\Command;

class SyncActorImages extends Command
{
    protected $signature = 'cinema:sync-actor-images {--slug=* : Sync only specific actor slugs} {--force : Re-download even if image exists}';

    protected $description = 'Download actor images from IMDb and store them inside the managed media root.';

    public function handle(ActorImageSyncService $actorImageSyncService): int
    {
        $slugs = collect((array) $this->option('slug'))
            ->filter(fn ($slug) => is_string($slug) && trim($slug) !== '')
            ->values();

        $actors = Actor::query()
            ->when($slugs->isNotEmpty(), fn ($query) => $query->whereIn('slug', $slugs->all()))
            ->when(!$this->option('force'), fn ($query) => $query->whereNull('image_path'))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        if ($actors->isEmpty()) {
            $this->info('No actors matched the sync criteria.');

            return self::SUCCESS;
        }

        $progressBar = $this->output->createProgressBar($actors->count());
        $progressBar->start();

        $synced = 0;
        $failed = 0;

        foreach ($actors as $actor) {
            try {
                $actorImageSyncService->syncFromImdb($actor);
                $synced++;
            } catch (\Throwable $exception) {
                $failed++;
                $this->newLine();
                $this->warn("Failed syncing {$actor->slug}: {$exception->getMessage()}");
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);
        $this->info("Actor image sync finished. Synced: {$synced}, Failed: {$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}

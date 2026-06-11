<?php

namespace App\Console\Commands;

use App\Services\Cinema\ImageCatalogPurgeService;
use Illuminate\Console\Command;

class PurgeCinemaImagesCommand extends Command
{
    protected $signature = 'cinema:purge-images {--force : Run without confirmation}';

    protected $description = 'Delete all ingested cinema images (disk + image_assets + processed metadata references)';

    public function handle(ImageCatalogPurgeService $purgeService): int
    {
        if (! $this->option('force') && ! $this->confirm('This will permanently delete all ingested images and related DB references. Continue?')) {
            $this->warn('Aborted.');

            return self::FAILURE;
        }

        $result = $purgeService->purgeAll();

        $this->info('Cinema image purge completed.');
        $this->table(
            ['Metric', 'Count'],
            [
                ['image_assets rows deleted', $result['assets_deleted']],
                ['physical files deleted', $result['files_deleted']],
                ['movies metadata updated', $result['movies_updated']],
                ['series records updated', $result['series_updated']],
                ['seasons updated', $result['seasons_updated']],
                ['episodes updated', $result['episodes_updated']],
                ['persons updated', $result['persons_updated']],
            ],
        );
        $this->line('Storage root: ' . $result['storage_root']);

        return self::SUCCESS;
    }
}

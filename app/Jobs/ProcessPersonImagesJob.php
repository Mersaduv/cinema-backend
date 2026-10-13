<?php

namespace App\Jobs;

use App\Models\Person;
use App\Services\Cinema\ContentImageIngestionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessPersonImagesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly int $personId,
        public readonly ?string $sourceUrl = null,
    ) {
        $this->onQueue('low');
    }

    public function handle(ContentImageIngestionService $contentImageIngestionService): void
    {
        $person = Person::query()->find($this->personId);

        if (! $person) {
            return;
        }

        $source = $this->sourceUrl ?: $person->profile_url;

        if (! filled($source)) {
            return;
        }

        $contentImageIngestionService->processPersonProfile($person, (string) $source);
    }
}

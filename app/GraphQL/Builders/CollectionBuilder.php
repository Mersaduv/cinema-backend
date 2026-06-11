<?php

namespace App\GraphQL\Builders;

use App\Models\Collection;
use Illuminate\Database\Eloquent\Builder;

class CollectionBuilder
{
    /**
     * @param  array<string, mixed>  $args
     */
    public function __invoke(mixed $root, array $args): Builder
    {
        $search = trim((string) ($args['search'] ?? ''));

        return Collection::query()
            ->visible()
            ->withCount([
                'movies as movies_count' => fn ($query) => $query
                    ->where('publish_status', 'published'),
            ])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($inner) use ($search) {
                    $inner
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->orderBy('sort_order')
            ->orderBy('name');
    }
}

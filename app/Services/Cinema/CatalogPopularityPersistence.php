<?php

namespace App\Services\Cinema;

use Illuminate\Support\Facades\Schema;

/**
 * Persists TMDB popularity signals into metadata + denormalized catalog columns.
 */
class CatalogPopularityPersistence
{
    /**
     * @param  array<string, mixed>  $metadata
     * @return array{metadata: array<string, mixed>, columns: array<string, mixed>}
     */
    public function applyToMetadata(
        array $metadata,
        ?float $popularity,
        ?int $voteCount,
        string $table,
    ): array {
        $tmdb = is_array($metadata['tmdb'] ?? null) ? $metadata['tmdb'] : [];

        if ($popularity !== null) {
            $tmdb['popularity'] = round($popularity, 3);
        }

        if ($voteCount !== null) {
            $tmdb['vote_count'] = max(0, $voteCount);
        }

        $metadata['tmdb'] = $tmdb;

        $columns = [];

        if (Schema::hasColumn($table, 'filter_popularity') && $popularity !== null) {
            $columns['filter_popularity'] = round($popularity, 3);
        }

        if (Schema::hasColumn($table, 'vote_count') && $voteCount !== null) {
            $columns['vote_count'] = max(0, $voteCount);
        }

        return [
            'metadata' => $metadata,
            'columns' => $columns,
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function extractFromValidated(array $validated): array
    {
        $popularity = array_key_exists('popularity', $validated) && $validated['popularity'] !== null
            ? (float) $validated['popularity']
            : null;

        $voteCount = array_key_exists('vote_count', $validated) && $validated['vote_count'] !== null
            ? (int) $validated['vote_count']
            : null;

        return [$popularity, $voteCount];
    }
}

<?php

namespace App\GraphQL\Queries;

use App\GraphQL\Support\MediaUrlHelper;
use App\Models\Movie;
use GraphQL\Type\Definition\ResolveInfo;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class WatchMovieQuery
{
    public function __construct(
        private readonly MediaUrlHelper $mediaUrlHelper,
    ) {}

    public function __invoke(mixed $root, array $args, GraphQLContext $context, ResolveInfo $resolveInfo): array
    {
        $movie = Movie::query()
            ->where('slug', $args['slug'])
            ->published()
            ->with('mediaAsset')
            ->firstOrFail();

        $metadata = is_array($movie->metadata) ? $movie->metadata : [];
        $playback = $metadata['playback'] ?? [];
        $downloads = $this->normalizeDownloads(data_get($playback, 'downloads', []));

        $hlsUrl = $this->mediaUrlHelper->resolveMoviePlaybackUrl($movie) ?? '';

        return [
            'hls_url' => $hlsUrl,
            'subtitle_url' => $this->mediaUrlHelper->resolveManagedOrExternalUrl($movie->subtitle_source_path),
            'dubbed_audio_url' => $this->mediaUrlHelper->resolveManagedOrExternalUrl($movie->dubbed_source_path),
            'audio_tracks' => $this->normalizeAudioTracks($movie),
            'downloads' => $downloads,
        ];
    }

    /**
     * @param  array<string, mixed>  $stored
     * @return list<array{quality: string, url: string, size_mb: ?int}>
     */
    private function normalizeDownloads(array $stored): array
    {
        if ($stored === []) {
            return [];
        }

        return collect($stored)
            ->filter(fn ($url, $quality) => filled($quality) && filled($url))
            ->map(fn ($url, $quality) => [
                'quality' => (string) $quality,
                'url' => (string) $url,
                'size_mb' => null,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{name: string, language: string, type: string}>
     */
    private function normalizeAudioTracks(Movie $movie): array
    {
        return collect($movie->audio_tracks ?? [])
            ->map(fn (array $track) => [
                'name' => (string) ($track['label'] ?? $track['name'] ?? 'صوت'),
                'language' => (string) ($track['language'] ?? 'und'),
                'type' => (string) ($track['type'] ?? 'original'),
            ])
            ->values()
            ->all();
    }
}

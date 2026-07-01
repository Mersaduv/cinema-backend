<?php

namespace App\Services\Cinema;

class SeriesHlsPathService
{
    public function __construct(
        private readonly MediaPathService $mediaPathService,
    ) {
    }

    public function formatSeasonDirectory(int|string $seasonNumber): string
    {
        $parsed = is_numeric($seasonNumber) ? (int) $seasonNumber : 0;

        if ($parsed < 0) {
            $parsed = 0;
        }

        return 'season-' . str_pad((string) $parsed, 2, '0', STR_PAD_LEFT);
    }

    public function formatEpisodeDirectory(int|string $episodeNumber): string
    {
        $parsed = is_numeric($episodeNumber) ? (int) $episodeNumber : 0;

        if ($parsed < 0) {
            $parsed = 0;
        }

        return 'episode-' . str_pad((string) $parsed, 2, '0', STR_PAD_LEFT);
    }

    public function normalizeHlsBaseUrl(?string $input): ?string
    {
        if (!filled($input)) {
            return null;
        }

        $path = rtrim($this->mediaPathService->coerceRemoteMediaUrl(trim((string) $input)), '/');

        if (preg_match('#master\.m3u8$#i', $path) === 1) {
            $path = preg_replace('#/master\.m3u8$#i', '', $path) ?? $path;
        }

        if (preg_match('#/episode-\d+$#i', $path) === 1) {
            $path = preg_replace('#/episode-\d+$#i', '', $path) ?? $path;
        }

        if (preg_match('#/season-\d+$#i', $path) === 1) {
            $path = preg_replace('#/season-\d+$#i', '', $path) ?? $path;
        }

        return $path !== '' ? $path : null;
    }

    /**
     * @return array{
     *   level: 'series'|'season'|'episode',
     *   series_base: string,
     *   season_base: ?string,
     *   episode_master: ?string,
     *   season_number: ?int,
     *   episode_number: ?int
     * }|null
     */
    public function parseHlsPackagePath(?string $input): ?array
    {
        if (!filled($input)) {
            return null;
        }

        $trimmed = rtrim($this->mediaPathService->coerceRemoteMediaUrl(trim((string) $input)), '/');
        $hasMaster = preg_match('#master\.m3u8$#i', $trimmed) === 1;
        $working = $hasMaster
            ? (preg_replace('#/master\.m3u8$#i', '', $trimmed) ?? $trimmed)
            : $trimmed;

        if (preg_match('#/episode-(\d+)$#i', $working, $episodeMatch) === 1) {
            $episodeNumber = (int) $episodeMatch[1];
            $episodeDir = $working;
            $seasonBase = preg_replace('#/episode-\d+$#i', '', $working) ?? $working;
            $seasonNumber = null;
            $seriesBase = $seasonBase;

            if (preg_match('#/season-(\d+)$#i', $seasonBase, $seasonMatch) === 1) {
                $seasonNumber = (int) $seasonMatch[1];
                $seriesBase = preg_replace('#/season-\d+$#i', '', $seasonBase) ?? $seasonBase;
            } else {
                $seriesBase = $this->normalizeHlsBaseUrl($seasonBase) ?? $seasonBase;
            }

            return [
                'level' => 'episode',
                'series_base' => $seriesBase,
                'season_base' => $seasonNumber !== null ? $seasonBase : null,
                'episode_master' => $hasMaster ? $trimmed : $this->joinUrl($episodeDir, 'master.m3u8'),
                'season_number' => $seasonNumber,
                'episode_number' => $episodeNumber,
            ];
        }

        if (preg_match('#/season-(\d+)$#i', $working, $seasonMatch) === 1) {
            $seasonNumber = (int) $seasonMatch[1];
            $seriesBase = preg_replace('#/season-\d+$#i', '', $working) ?? $working;

            return [
                'level' => 'season',
                'series_base' => $seriesBase,
                'season_base' => $working,
                'episode_master' => $hasMaster ? $trimmed : null,
                'season_number' => $seasonNumber,
                'episode_number' => null,
            ];
        }

        $seriesBase = $this->normalizeHlsBaseUrl($working) ?? $working;

        return [
            'level' => 'series',
            'series_base' => $seriesBase,
            'season_base' => null,
            'episode_master' => $hasMaster ? $trimmed : null,
            'season_number' => null,
            'episode_number' => null,
        ];
    }

    public function buildSeasonHlsBase(?string $seriesBase, int|string $seasonNumber): ?string
    {
        $base = $this->normalizeHlsBaseUrl($seriesBase);

        if (!filled($base)) {
            return null;
        }

        return $this->joinUrl((string) $base, $this->formatSeasonDirectory($seasonNumber));
    }

    public function buildEpisodeHlsUrl(?string $seasonBase, int|string $episodeNumber): ?string
    {
        if (!filled($seasonBase)) {
            return null;
        }

        $episodeDir = $this->joinUrl(
            rtrim(trim((string) $seasonBase), '/'),
            $this->formatEpisodeDirectory($episodeNumber),
        );

        return $this->joinUrl($episodeDir, 'master.m3u8');
    }

    public function buildEpisodeHlsUrlFromSeries(
        ?string $seriesBase,
        int|string $seasonNumber,
        int|string $episodeNumber,
    ): ?string {
        $seasonBase = $this->buildSeasonHlsBase($seriesBase, $seasonNumber);

        return $this->buildEpisodeHlsUrl($seasonBase, $episodeNumber);
    }

    /**
     * @return array{hls_url: ?string, source: 'episode'|'season'|'series'|''}
     */
    public function resolveEpisodeHlsUrl(
        ?string $seriesBase,
        ?string $seasonBase,
        ?string $episodeHls,
        int|string $seasonNumber,
        int|string $episodeNumber,
    ): array {
        if (filled($episodeHls)) {
            return [
                'hls_url' => trim((string) $episodeHls),
                'source' => 'episode',
            ];
        }

        if (filled($seasonBase)) {
            $fromSeason = $this->buildEpisodeHlsUrl($seasonBase, $episodeNumber);

            if (filled($fromSeason)) {
                return [
                    'hls_url' => $fromSeason,
                    'source' => 'season',
                ];
            }
        }

        if (filled($seriesBase)) {
            $normalizedSeriesBase = $this->normalizeHlsBaseUrl($seriesBase);

            if (filled($normalizedSeriesBase)) {
                $flatFromSeries = $this->buildEpisodeHlsUrl($normalizedSeriesBase, $episodeNumber);

                if (filled($flatFromSeries)) {
                    return [
                        'hls_url' => $flatFromSeries,
                        'source' => 'series',
                    ];
                }
            }

            $fromSeries = $this->buildEpisodeHlsUrlFromSeries($seriesBase, $seasonNumber, $episodeNumber);

            if (filled($fromSeries)) {
                return [
                    'hls_url' => $fromSeries,
                    'source' => 'series',
                ];
            }
        }

        return [
            'hls_url' => null,
            'source' => '',
        ];
    }

    private function joinUrl(string $base, string $segment): string
    {
        $base = $this->mediaPathService->coerceRemoteMediaUrl($base);

        return rtrim($base, '/') . '/' . ltrim($segment, '/');
    }
}

<?php

namespace App\Services\Cinema;

class ImageVariantUrlBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function buildFromPublicPath(
        string $publicPath,
        ?string $mimeType = null,
        ?int $width = null,
        ?int $height = null,
    ): array {
        $original = $this->buildAbsoluteOrRelativeUrl($publicPath);
        $isSvg = $mimeType === 'image/svg+xml' || str_ends_with(strtolower($publicPath), '.svg');

        $blurParams = $isSvg
            ? ['width' => 256, 'quality' => 95, 'sharpen' => 60]
            : [
                'width' => (int) config('cinema_images.blur_width', 20),
                'quality' => (int) config('cinema_images.blur_quality', 10),
                'sharpen' => (int) config('cinema_images.blur_sharpen', 0),
            ];

        $responsiveQuality = $isSvg ? 95 : (int) config('cinema_images.responsive_quality', 65);
        $responsiveSharpen = (int) config('cinema_images.responsive_sharpen', 60);
        $defaultSrcWidth = (int) config('cinema_images.default_src_width', 384);
        $widths = config('cinema_images.responsive_widths', [256, 384, 640, 750, 828, 1080, 1200, 1920, 2048, 3840]);

        $srcset = [];
        foreach ($widths as $variantWidth) {
            $url = $this->appendQueryParams($original, [
                'width' => (int) $variantWidth,
                'quality' => $responsiveQuality,
                'sharpen' => $responsiveSharpen,
            ]);

            $srcset[] = [
                'url' => $url,
                'width' => (int) $variantWidth,
                'descriptor' => ((int) $variantWidth) . 'w',
            ];
        }

        $src = $this->appendQueryParams($original, [
            'width' => $defaultSrcWidth,
            'quality' => $responsiveQuality,
            'sharpen' => $responsiveSharpen,
        ]);

        $srcsetString = collect($srcset)
            ->map(fn (array $item) => $item['url'] . ' ' . $item['descriptor'])
            ->implode(', ');

        return [
            'original' => $original,
            'blur' => $this->appendQueryParams($original, $blurParams),
            'src' => $src,
            'srcset' => $srcset,
            'srcset_string' => $srcsetString,
            'width' => $width,
            'height' => $height,
            'mime_type' => $mimeType,
        ];
    }

    public function buildAbsoluteOrRelativeUrl(string $publicPath): string
    {
        $trimmed = trim(str_replace('\\', '/', $publicPath));

        if (preg_match('#^https?://#i', $trimmed) === 1) {
            return $trimmed;
        }

        $normalized = '/' . trim($trimmed, '/');
        $baseUrl = rtrim((string) config('cinema_images.public_base_url', ''), '/');

        if ($baseUrl === '') {
            return $normalized;
        }

        return $baseUrl . $normalized;
    }

    public function isManagedPublicUrl(?string $url): bool
    {
        if (! filled($url)) {
            return false;
        }

        $prefix = (string) config('cinema_images.public_prefix', '/images');
        $normalizedPrefix = '/' . trim($prefix, '/');
        $baseUrl = rtrim((string) config('cinema_images.public_base_url', ''), '/');

        if ($baseUrl !== '' && str_starts_with($url, $baseUrl . $normalizedPrefix . '/')) {
            return true;
        }

        return str_starts_with($url, $normalizedPrefix . '/');
    }

    /**
     * @param  array<string, int|string>  $params
     */
    private function appendQueryParams(string $url, array $params): string
    {
        $separator = str_contains($url, '?') ? '&' : '?';

        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        return $url . $separator . $query;
    }
}

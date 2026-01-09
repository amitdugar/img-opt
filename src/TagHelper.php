<?php

declare(strict_types=1);

namespace ImgOpt;

final class TagHelper
{
    private ImageService $service;

    public function __construct(ImageService $service)
    {
        $this->service = $service;
    }

    /**
     * Build a <picture> tag with srcset for the given widths.
     *
     * @param int[] $widths
     * @param array<string,string> $imgAttributes
     */
    public function picture(string $sourcePath, array $widths, string $acceptHeader = '', string $sizes = '100vw', array $imgAttributes = []): string
    {
        $uniqueWidths = array_values(array_unique(array_map('intval', $widths)));
        sort($uniqueWidths);
        $sourceWeb = $this->service->publicPath($sourcePath);
        if ($this->isSvg($sourcePath)) {
            $attrString = $this->attributes(array_merge([
                'src' => $this->escape($sourceWeb),
                'loading' => 'lazy',
            ], $imgAttributes));

            return sprintf('<img %s>', $attrString);
        }

        $accept = strtolower($acceptHeader);
        $acceptsAvif = $accept === '' || str_contains($accept, 'image/avif');
        $acceptsWebp = $accept === '' || str_contains($accept, 'image/webp');

        $sources = [];
        if ($acceptsAvif && $this->service->supportsFormat('avif')) {
            $srcset = $this->buildSrcset($sourcePath, $uniqueWidths, 'avif');
            if ($srcset !== '') {
                $sources[] = sprintf('<source srcset="%s" type="image/avif">', $srcset);
            }
        }
        if ($acceptsWebp && $this->service->supportsFormat('webp')) {
            $srcset = $this->buildSrcset($sourcePath, $uniqueWidths, 'webp');
            if ($srcset !== '') {
                $sources[] = sprintf('<source srcset="%s" type="image/webp">', $srcset);
            }
        }

        $fallbackFormat = $this->fallbackFormat($sourcePath);
        $fallbackSrcset = $this->buildSrcset($sourcePath, $uniqueWidths, $fallbackFormat);
        $fallbackWidth = $uniqueWidths ? end($uniqueWidths) : 0;
        $fallback = $fallbackWidth > 0
            ? $this->escape($this->service->publicPath($this->service->ensureVariant($sourcePath, $fallbackWidth, '', $fallbackFormat)))
            : $this->escape($sourceWeb);

        $attrString = $this->attributes(array_merge([
            'srcset' => $fallbackSrcset,
            'sizes'  => $sizes,
            'src'    => $fallback ? explode(' ', $fallback)[0] : $this->escape($sourceWeb),
            'loading' => 'lazy',
        ], $imgAttributes));

        $sourceMarkup = $sources ? implode('', $sources) : '';
        return sprintf('<picture>%s<img %s></picture>', $sourceMarkup, $attrString);
    }

    private function attributes(array $attrs): string
    {
        $pairs = [];
        foreach ($attrs as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $pairs[] = sprintf('%s="%s"', htmlspecialchars((string) $key, ENT_QUOTES), $this->escape((string) $value));
        }

        return implode(' ', $pairs);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES);
    }

    /**
     * @param int[] $widths
     */
    private function buildSrcset(string $sourcePath, array $widths, string $format): string
    {
        if (empty($widths)) {
            return '';
        }

        $srcsetParts = [];
        foreach ($widths as $w) {
            $variant = $this->service->ensureVariant($sourcePath, $w, '', $format);
            $srcsetParts[] = $this->escape($this->service->publicPath($variant)) . ' ' . $w . 'w';
        }

        return implode(', ', $srcsetParts);
    }

    private function fallbackFormat(string $sourcePath): string
    {
        $ext = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
        return match ($ext) {
            'avif' => 'avif',
            'webp' => 'webp',
            'png' => 'png',
            'jpg', 'jpeg' => 'jpeg',
            default => 'jpeg',
        };
    }

    private function isSvg(string $sourcePath): bool
    {
        $ext = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
        return $ext === 'svg' || $ext === 'svgz';
    }
}

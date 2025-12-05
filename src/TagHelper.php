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

        $srcsetParts = [];
        foreach ($uniqueWidths as $w) {
            $variant = $this->service->ensureVariant($sourcePath, $w, $acceptHeader);
            $srcsetParts[] = $this->escape($variant) . ' ' . $w . 'w';
        }

        $srcset = implode(', ', $srcsetParts);
        $fallback = end($srcsetParts) ?: $this->escape($sourcePath);

        $attrString = $this->attributes(array_merge([
            'srcset' => $srcset,
            'sizes'  => $sizes,
            'src'    => $fallback ? explode(' ', $fallback)[0] : $this->escape($sourcePath),
            'loading' => 'lazy',
        ], $imgAttributes));

        return sprintf('<picture><img %s></picture>', $attrString);
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
}

<?php

declare(strict_types=1);

namespace ImgOpt;

final class Config
{
    public const DEFAULT_BREAKPOINTS = [480, 768, 1080, 1440, 1920];

    public string $cacheRoot;
    public array $breakpoints;
    public array $quality; // ['avif' => int, 'webp' => int, 'jpeg' => int, 'png' => int]
    public int $maxWidth;

    /**
     * @param array{
     *   cache_root?:string,
     *   breakpoints?:int[],
     *   quality?:array{avif?:int,webp?:int,jpeg?:int,png?:int},
     *   max_width?:int
     * } $options
     */
    public static function fromArray(array $options): self
    {
        $instance = new self();
        $instance->cacheRoot = rtrim($options['cache_root'] ?? sys_get_temp_dir() . '/img-opt-cache', '/');
        $instance->breakpoints = array_values(array_unique(array_map('intval', $options['breakpoints'] ?? self::DEFAULT_BREAKPOINTS)));
        sort($instance->breakpoints);

        $quality = $options['quality'] ?? [];
        $instance->quality = [
            'avif' => isset($quality['avif']) ? (int) $quality['avif'] : 42,
            'webp' => isset($quality['webp']) ? (int) $quality['webp'] : 80,
            'jpeg' => isset($quality['jpeg']) ? (int) $quality['jpeg'] : 82,
            'png'  => isset($quality['png']) ? (int) $quality['png'] : 0, // imagick ignores quality for png compression level
        ];

        $instance->maxWidth = isset($options['max_width']) ? (int) $options['max_width'] : 0;

        return $instance;
    }
}

<?php

declare(strict_types=1);

namespace ImgOpt;

use Symfony\Component\Filesystem\Filesystem;

final class ImageService
{
    private Config $config;
    private Capabilities $capabilities;
    private VariantCache $cache;
    private ImageProcessor $processor;
    private Filesystem $fs;

    public function __construct(Config $config, ?Capabilities $capabilities = null, ?VariantCache $cache = null, ?ImageProcessor $processor = null, ?Filesystem $fs = null)
    {
        $this->config = $config;
        $this->capabilities = $capabilities ?? new Capabilities();
        $this->cache = $cache ?? new VariantCache($config->cacheRoot, null, $config->publicRoot);
        $this->processor = $processor ?? new ImageProcessor($config, $this->capabilities);
        $this->fs = $fs ?? new Filesystem();
        $this->cache->ensureDirectory();
    }

    /**
     * Ensure a cached variant exists and return its path.
     */
    public function ensureVariant(string $source, int $width, string $acceptHeader = '', ?string $forceFormat = null): string
    {
        if ($this->isSvg($source)) {
            return $source;
        }

        [$target, $format, $quality, $safeWidth] = $this->resolveTarget($source, $width, $acceptHeader, $forceFormat);
        if ($this->fs->exists($target)) {
            return $target;
        }

        $this->processor->generate($source, $safeWidth, $format, $target, $quality);
        return $target;
    }

    /**
     * Ensure a cached variant exists and return its public (web/CDN) URL.
     *
     * Equivalent to publicPath(ensureVariant(...)); use this when you need the URL to serve
     * rather than the filesystem path, so the generated file and the URL never diverge.
     */
    public function ensureUrl(string $source, int $width, string $acceptHeader = '', ?string $forceFormat = null): string
    {
        return $this->publicPath($this->ensureVariant($source, $width, $acceptHeader, $forceFormat));
    }

    /**
     * Public URL of an ALREADY-CACHED variant, or null if it hasn't been generated yet.
     * NEVER generates (unlike ensureUrl()/ensureVariant()).
     *
     * For hot paths that render many images and must not pay generation cost (e.g. a
     * 400-item product grid) — callers fall back to the original on null. The target is keyed
     * exactly the way ensureVariant() keys it (same format/quality/width clamping), so a hit
     * here guarantees the file ensureVariant() would have produced.
     */
    public function warmedUrl(string $source, int $width, string $acceptHeader = '', ?string $forceFormat = null): ?string
    {
        // SVGs are served as-is (never cached/generated), so they are always "warm".
        if ($this->isSvg($source)) {
            return $this->publicPath($source);
        }

        [$target] = $this->resolveTarget($source, $width, $acceptHeader, $forceFormat);
        return is_file($target) ? $this->publicPath($target) : null;
    }

    /**
     * Resolve the cache target for a source the exact same way generation does, so lookups and
     * writes always agree on the filename.
     *
     * @return array{0:string,1:string,2:int,3:int} [targetPath, format, quality, clampedWidth]
     */
    private function resolveTarget(string $source, int $width, string $acceptHeader, ?string $forceFormat): array
    {
        $format = $forceFormat ? strtolower($forceFormat) : $this->capabilities->bestFormatForAccept($acceptHeader);
        $format = $this->normalizeFormat($format, $source);
        $quality = $this->qualityFor($format);
        $safeWidth = $this->clampWidth($source, $width);
        $target = $this->cache->getPath($source, $safeWidth, $format, $quality);

        return [$target, $format, $quality, $safeWidth];
    }

    public function supportsFormat(string $format): bool
    {
        if (!$this->capabilities->isImagickAvailable()) {
            return false;
        }

        $fmt = strtolower($format);
        if (in_array($fmt, ['jpeg', 'jpg', 'png'], true)) {
            return true;
        }

        return $this->capabilities->supports($fmt);
    }

    public function publicPath(string $absolutePath): string
    {
        $publicRoot = rtrim($this->config->publicRoot ?? '', '/');
        $cdnBase = rtrim($this->config->cdnBase ?? '', '/');
        if ($absolutePath === '') {
            return $absolutePath;
        }

        if (preg_match('#^https?://#i', $absolutePath)) {
            return $absolutePath;
        }

        if ($publicRoot !== '' && str_starts_with($absolutePath, $publicRoot . '/')) {
            $web = '/' . ltrim(substr($absolutePath, strlen($publicRoot)), '/');
            return $cdnBase !== '' ? $cdnBase . $web : $web;
        }

        if ($publicRoot !== '' && $absolutePath === $publicRoot) {
            return $cdnBase !== '' ? $cdnBase . '/' : '/';
        }

        if ($absolutePath[0] === '/') {
            return $cdnBase !== '' ? $cdnBase . $absolutePath : $absolutePath;
        }

        return $absolutePath;
    }

    /**
     * Determine best quality for format.
     */
    private function qualityFor(string $format): int
    {
        $key = strtolower($format);
        return $this->config->quality[$key] ?? 80;
    }

    private function normalizeFormat(string $format, string $source): string
    {
        $fmt = strtolower($format);
        $ext = strtolower(pathinfo($source, PATHINFO_EXTENSION));
        $allowed = ['avif', 'webp', 'jpeg', 'jpg', 'png'];

        if (!in_array($fmt, $allowed, true)) {
            $fmt = ($ext === 'png') ? 'png' : 'jpeg';
        }

        // Fall back if not supported
        if ($fmt === 'avif' && !$this->capabilities->supports('AVIF')) {
            $fmt = 'webp';
        }
        if ($fmt === 'webp' && !$this->capabilities->supports('WEBP')) {
            $fmt = ($ext === 'png') ? 'png' : 'jpeg';
        }

        if ($fmt === 'jpg') {
            $fmt = 'jpeg';
        }

        return $fmt;
    }

    private function clampWidth(string $source, int $requested): int
    {
        $max = $this->config->maxWidth;
        $req = $requested > 0 ? $requested : 0;
        if ($max > 0 && ($req === 0 || $req > $max)) {
            $req = $max;
        }

        // Do not upscale
        [$width] = @getimagesize($source) ?: [0];
        if ($width > 0 && $req > $width) {
            $req = $width;
        }

        return $req;
    }

    private function isSvg(string $source): bool
    {
        $ext = strtolower(pathinfo($source, PATHINFO_EXTENSION));
        return $ext === 'svg' || $ext === 'svgz';
    }
}

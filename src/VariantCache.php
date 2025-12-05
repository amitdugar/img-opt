<?php

declare(strict_types=1);

namespace ImgOpt;

use Symfony\Component\Filesystem\Filesystem;

final class VariantCache
{
    private string $cacheRoot;
    private Filesystem $fs;

    public function __construct(string $cacheRoot, ?Filesystem $fs = null)
    {
        $this->cacheRoot = rtrim($cacheRoot, '/');
        $this->fs = $fs ?? new Filesystem();
    }

    public function getPath(string $source, int $width, string $format, int $quality): string
    {
        $hash = $this->hash($source, $width, $format, $quality);
        $ext = strtolower($format);
        $basename = sprintf('%s-w%d.%s', $hash, $width, $ext);
        return $this->cacheRoot . '/' . $basename;
    }

    public function ensureDirectory(): void
    {
        if (!$this->fs->exists($this->cacheRoot)) {
            $this->fs->mkdir($this->cacheRoot, 0775);
        }
    }

    private function hash(string $source, int $width, string $format, int $quality): string
    {
        $mtime = @filemtime($source) ?: 0;
        $payload = implode('|', [$source, $mtime, $width, strtolower($format), $quality]);
        return substr(sha1($payload), 0, 16);
    }
}

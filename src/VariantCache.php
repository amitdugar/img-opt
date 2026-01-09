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
        $readable = $this->readableName($source);
        $basename = sprintf('%s-w%d-q%d-%s.%s', $readable, $width, $quality, $hash, $ext);
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

    private function readableName(string $source): string
    {
        $clean = preg_split('/[?#]/', $source, 2)[0] ?? $source;
        $ext = strtolower(pathinfo($clean, PATHINFO_EXTENSION));
        $base = pathinfo($clean, PATHINFO_FILENAME);
        $parent = basename(dirname($clean));

        $parts = [];
        if ($parent !== '' && $parent !== '.' && $parent !== DIRECTORY_SEPARATOR) {
            $parts[] = $parent;
        }
        if ($base !== '') {
            $parts[] = $base;
        } elseif ($ext !== '') {
            $parts[] = $ext;
        } else {
            $parts[] = 'image';
        }

        $name = implode('-', $parts);
        $name = strtolower($name);
        $name = preg_replace('/[^a-z0-9._-]+/', '-', $name) ?? 'image';
        $name = trim($name, '-.');

        return $name !== '' ? $name : 'image';
    }
}

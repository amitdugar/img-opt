<?php

declare(strict_types=1);

namespace ImgOpt;

use Symfony\Component\Filesystem\Filesystem;

final class VariantCache
{
    private string $cacheRoot;
    private string $baseRoot;
    private Filesystem $fs;

    /**
     * @param string $baseRoot Optional root the source paths live under. When set, the cache
     *                         identity is derived from the path relative to this root, so the
     *                         same image yields the same variant name regardless of where the
     *                         project is checked out on disk. Leave empty to use the full path.
     */
    public function __construct(string $cacheRoot, ?Filesystem $fs = null, string $baseRoot = '')
    {
        $this->cacheRoot = rtrim($cacheRoot, '/');
        $this->fs = $fs ?? new Filesystem();
        $this->baseRoot = rtrim($baseRoot, '/');
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
        $identity = $this->relativeIdentity($source);
        $payload = implode('|', [$identity, $mtime, $width, strtolower($format), $quality]);
        return substr(sha1($payload), 0, 16);
    }

    /**
     * Path used to identify the source for hashing. Relative to baseRoot when the source lives
     * under it (so names are stable across checkout locations); otherwise the cleaned full path.
     */
    private function relativeIdentity(string $source): string
    {
        $clean = preg_split('/[?#]/', $source, 2)[0] ?? $source;
        if ($this->baseRoot !== '' && str_starts_with($clean, $this->baseRoot . '/')) {
            return ltrim(substr($clean, strlen($this->baseRoot)), '/');
        }

        return $clean;
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

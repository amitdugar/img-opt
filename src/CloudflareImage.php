<?php

declare(strict_types=1);

namespace ImgOpt;

/**
 * Helper for Cloudflare Image Resizing URLs and markup.
 *
 * - Builds /cdn-cgi/image/... URLs with defaults.
 * - Provides an <img> helper with srcset and anti-upscaling guard.
 * - Falls back to local assets (optionally using nearby .avif/.webp) when disabled.
 */
final class CloudflareImage
{
    private string $publicPath;
    private string $cacheFile;
    private int $defaultQuality;
    private bool $enabled;

    public function __construct(string $publicPath, ?string $cacheFile = null, int $defaultQuality = 85, bool $enabled = true)
    {
        $this->publicPath = rtrim($publicPath, '/');
        $this->defaultQuality = $defaultQuality;
        $this->enabled = $enabled;

        $this->cacheFile = $cacheFile ?: dirname($this->publicPath) . '/storage/cache/image-sizes.json';
        $dir = dirname($this->cacheFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (!is_file($this->cacheFile)) {
            $this->atomicWrite($this->cacheFile, json_encode([]));
        }
    }

    /**
     * Build a Cloudflare Image Resizing URL.
     *
     * @param array<string,int|string> $opts
     */
    public function url(string $path, array $opts): string
    {
        if (!$this->enabled) {
            return '/' . ltrim($path, '/');
        }

        $opts = array_replace([
            'fit' => 'scale-down',
            'format' => 'auto',
            'quality' => $this->defaultQuality,
        ], $opts);

        $pairs = [];
        foreach ($opts as $k => $v) {
            $pairs[] = $k . '=' . $v;
        }

        return '/cdn-cgi/image/' . implode(',', $pairs) . '/' . ltrim($path, '/');
    }

    /**
     * Render an <img> with srcset. Prevents upscaling.
     *
     * @param int[] $widths
     * @param array<string,string|string[]> $attrs
     */
    public function img(string $path, array $widths = [], array $attrs = [], int $default = 600, ?int $forceMaxWidth = null): string
    {
        if (isset($attrs['class']) && is_array($attrs['class'])) {
            $attrs['class'] = implode(' ', $attrs['class']);
        }

        [$srcW, $srcH] = $this->getSourceSize($path);
        $maxWidth = $forceMaxWidth ?? ($srcW ?: null);

        if ($maxWidth !== null) {
            $default = min($default, $maxWidth);
            if ($widths) {
                $widths = array_values(array_filter($widths, fn ($w) => $w <= $maxWidth));
            }
        }

        if (!$this->enabled) {
            return $this->renderLocalPicture($path, $attrs, $srcW, $srcH, $default);
        }

        $src = $this->url($path, ['width' => $default]);

        $srcset = '';
        if (!empty($widths)) {
            $parts = [];
            foreach ($widths as $w) {
                $parts[] = $this->url($path, ['width' => $w]) . " {$w}w";
            }
            $srcset = implode(', ', $parts);
        }

        $sizes = $srcset ? "(max-width: {$default}px) 90vw, {$default}px" : '';

        $outW = $srcW ?: $default;
        $outH = $srcH ?: $default;

        $attrStr = $this->attrString($attrs);
        $srcsetAttr = $srcset ? ' srcset="' . htmlspecialchars($srcset, ENT_QUOTES) . '"' : '';
        $sizesAttr = $sizes ? ' sizes="' . htmlspecialchars($sizes, ENT_QUOTES) . '"' : '';

        return <<<HTML
<img
  src="{$src}"{$attrStr}
  width="{$outW}" height="{$outH}"{$srcsetAttr}{$sizesAttr}>
HTML;
    }

    /** Background helper (cover + gravity=auto by default). */
    public function bgUrl(string $path, int $width, ?int $height = null, array $extraOpts = []): string
    {
        $opts = ['width' => $width, 'fit' => 'cover', 'gravity' => 'auto'];
        if ($height !== null) {
            $opts['height'] = $height;
        }
        return $this->url($path, array_replace($opts, $extraOpts));
    }

    private function renderLocalPicture(string $path, array $attrs, ?int $srcW, ?int $srcH, int $default): string
    {
        $srcPath = '/' . ltrim($path, '/');
        $outW = $srcW ?: $default;
        $outH = $srcH ?: $default;

        $attrStr = $this->attrString($attrs);

        $dotPos = strrpos($srcPath, '.');
        $base = $dotPos !== false ? substr($srcPath, 0, $dotPos) : $srcPath;
        $ext = $dotPos !== false ? strtolower(substr($srcPath, $dotPos + 1)) : '';

        $avifPath = $base . '.avif';
        $webpPath = $base . '.webp';

        $avifLocal = $this->mapToLocal($avifPath);
        $webpLocal = $this->mapToLocal($webpPath);

        $avifWeb = $avifLocal && is_file($avifLocal) ? $avifPath : null;
        $webpWeb = $webpLocal && is_file($webpLocal) ? $webpPath : null;

        $origMime = match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'avif' => 'image/avif',
            default => 'image/jpeg',
        };

        $sources = '';
        if ($avifWeb) {
            $sources .= '    <source srcset="' . htmlspecialchars($avifWeb, ENT_QUOTES) . "\" type=\"image/avif\">\n";
        }
        if ($webpWeb) {
            $sources .= '    <source srcset="' . htmlspecialchars($webpWeb, ENT_QUOTES) . "\" type=\"image/webp\">\n";
        }

        return <<<HTML
<picture>
{$sources}    <source srcset="{$srcPath}" type="{$origMime}">
    <img src="{$srcPath}"{$attrStr} width="{$outW}" height="{$outH}">
</picture>
HTML;
    }

    private function attrString(array $attrs): string
    {
        $attrStr = '';
        foreach ($attrs as $k => $v) {
            if ($k === 'class' && is_array($v)) {
                $v = implode(' ', $v);
            }
            $attrStr .= ' ' . $k . '="' . htmlspecialchars((string) $v, ENT_QUOTES) . '"';
        }
        return $attrStr;
    }

    /** Map a web path to a local file path; return null if not under publicPath. */
    private function mapToLocal(string $path): ?string
    {
        if (preg_match('#^https?://#i', $path)) {
            return null;
        }

        $p = $path;
        if ($p === '' || $p[0] !== '/') {
            $p = '/' . $p;
        }
        $fs = $this->publicPath . $p;

        $real = @realpath($fs);
        $root = @realpath($this->publicPath);
        if (!$real || !$root) {
            return null;
        }

        if (strpos($real, $root) !== 0) {
            return null;
        }

        return is_file($real) ? $real : null;
    }

    /** Get source image size with JSON file cache. Returns [w,h] or [null,null]. */
    private function getSourceSize(string $path): array
    {
        if (preg_match('/\\.svgz?($|\\?)/i', $path)) {
            return [null, null];
        }

        $local = $this->mapToLocal($path);
        $key = $local ?: $path;

        $cache = $this->loadCache();

        if (isset($cache[$key])) {
            $entry = $cache[$key];
            if ($local) {
                $mt = @filemtime($local) ?: 0;
                if (($entry['mtime'] ?? -1) === $mt) {
                    return [$entry['w'] ?? null, $entry['h'] ?? null];
                }
            } else {
                return [$entry['w'] ?? null, $entry['h'] ?? null];
            }
        }

        $w = $h = null;
        $mt = 0;
        if ($local) {
            $info = @getimagesize($local);
            if (is_array($info) && isset($info[0], $info[1])) {
                $w = (int) $info[0];
                $h = (int) $info[1];
            }
            $mt = @filemtime($local) ?: 0;
        }

        $cache[$key] = ['w' => $w, 'h' => $h, 'mtime' => $mt];
        $this->saveCache($cache);

        return [$w, $h];
    }

    /** Load JSON cache with shared lock. */
    private function loadCache(): array
    {
        $fh = @fopen($this->cacheFile, 'c+');
        if (!$fh) {
            return [];
        }
        $cache = [];
        if (flock($fh, LOCK_SH)) {
            clearstatcache(true, $this->cacheFile);
            $size = filesize($this->cacheFile);
            $json = $size ? fread($fh, $size) : '';
            $cache = json_decode($json, true) ?: [];
            flock($fh, LOCK_UN);
        }
        fclose($fh);
        return $cache;
    }

    /** Save JSON cache atomically. */
    private function saveCache(array $data): void
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES);
        $this->atomicWrite($this->cacheFile, $json);
    }

    /** Atomic write via temp file + rename. */
    private function atomicWrite(string $file, string $contents): void
    {
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $tmp = tempnam($dir, 'imgcache_');
        if ($tmp === false) {
            return;
        }
        file_put_contents($tmp, $contents, LOCK_EX);
        @chmod($tmp, 0664);
        @rename($tmp, $file);
    }
}

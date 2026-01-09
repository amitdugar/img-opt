<?php

declare(strict_types=1);

namespace ImgOpt;

final class ImgOpt
{
    private ImageService $service;
    private TagHelper $helper;
    private ?CloudflareImage $cloudflare;
    private bool $useCloudflare;
    private string $publicPath;

    public function __construct(ImageService $service, string $publicPath, ?CloudflareImage $cloudflare = null, bool $useCloudflare = false)
    {
        $this->service = $service;
        $this->helper = new TagHelper($service);
        $this->publicPath = rtrim($publicPath, '/');
        $this->cloudflare = $cloudflare;
        $this->useCloudflare = $useCloudflare;
    }

    public static function fromConfig(Config $config, string $publicPath, bool $useCloudflare = false, ?CloudflareImage $cloudflare = null): self
    {
        $config->publicRoot = rtrim($publicPath, '/');
        $service = new ImageService($config);

        if ($useCloudflare && $cloudflare === null) {
            $cloudflare = new CloudflareImage($publicPath, null, 85, true);
        }

        return new self($service, $publicPath, $cloudflare, $useCloudflare);
    }

    /**
     * Build an image tag using Cloudflare when enabled, otherwise local variants.
     *
     * @param int[] $widths
     * @param array<string,string> $imgAttributes
     */
    public function picture(string $sourcePath, array $widths, string $acceptHeader = '', string $sizes = '100vw', array $imgAttributes = [], ?int $default = null): string
    {
        if ($this->useCloudflare && $this->cloudflare !== null) {
            $webPath = $this->toWebPath($sourcePath);
            if ($webPath !== null) {
                $defaultWidth = $default ?? ($widths ? max($widths) : 600);
                return $this->cloudflare->img($webPath, $widths, $imgAttributes, (int) $defaultWidth);
            }
        }

        $localPath = $this->toLocalPath($sourcePath);
        return $this->helper->picture($localPath, $widths, $acceptHeader, $sizes, $imgAttributes);
    }

    private function toWebPath(string $path): ?string
    {
        if ($path === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }

        if ($path[0] === '/') {
            return $path;
        }

        $publicRoot = $this->publicPath . '/';
        if (str_starts_with($path, $publicRoot)) {
            return '/' . ltrim(substr($path, strlen($publicRoot)), '/');
        }

        if (str_starts_with($path, $this->publicPath)) {
            return '/' . ltrim(substr($path, strlen($this->publicPath)), '/');
        }

        return null;
    }

    private function toLocalPath(string $path): string
    {
        if ($path === '') {
            return $path;
        }

        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }

        if ($path[0] === '/') {
            return $this->publicPath . $path;
        }

        return $path;
    }
}

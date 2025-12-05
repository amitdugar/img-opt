# ImgOpt (PHP)

Reusable PHP helpers for image delivery and batch conversion with AVIF/WebP support. Includes:
- In-app service to generate cached variants (resize + format negotiation)
- `<picture>` helper for srcset generation
- CLI tool for one-time/bulk conversion
- Cloudflare helper for /cdn-cgi/image URLs and dev fallback

Namespace: `ImgOpt`. Dependencies: PHP 8.1+, `ext-imagick`, Symfony Console/Filesystem/Finder.
Versioning: Semantic Versioning (SemVer) via git tags (e.g., `0.0.1`).

## Install
```bash
composer require amitdugar/img-opt
```

## Quick start (PHP)
```php
use ImgOpt\Config;
use ImgOpt\ImageService;
use ImgOpt\TagHelper;

$config = Config::fromArray([
    'cache_root' => __DIR__ . '/storage/img-cache',
    'max_width'  => 0, // keep original unless a smaller width is requested
    'quality'    => ['avif' => 42, 'webp' => 80, 'jpeg' => 82],
]);
$service = new ImageService($config);
$helper  = new TagHelper($service);

// Generate variants and emit a <picture> tag with srcset
echo $helper->picture(
    __DIR__ . '/public/img/photo.jpg',
    [480, 768, 1080, 1600],
    $_SERVER['HTTP_ACCEPT'] ?? '',
    '100vw',
    ['alt' => 'Sample photo']
);
```

- The helper will pick AVIF if supported, otherwise WebP, otherwise JPEG/PNG, and cache the generated variants under `cache_root`.
- `ImageService::ensureVariant($source, $width, $acceptHeader, $forceFormat)` is available if you just need the cached file path.

## Cloudflare Image Resizing helper
```php
use ImgOpt\CloudflareImage;

$cf = new CloudflareImage(
    publicPath: __DIR__ . '/public',           // local public root (for size checks)
    cacheFile: __DIR__ . '/storage/image-sizes.json', // intrinsic-size cache (optional)
    defaultQuality: 85,
    enabled: true                               // set false in dev to bypass CF
);

echo $cf->img(
    '/img/photo.jpg',
    [480, 768, 1080, 1600],                     // widths for srcset (will clamp to intrinsic)
    ['alt' => 'Sample photo', 'class' => 'img-fluid'],
    default: 800
);
```
- Builds `/cdn-cgi/image/...` URLs when enabled. In dev/disabled mode, falls back to a local `<picture>` and will use adjacent `.avif`/`.webp` files if they exist.
- Caches intrinsic sizes in a small JSON file to avoid repeated `getimagesize` calls.
- Helper avoids upscaling and auto-adds `width`/`height` for layout stability.

## CLI (bulk conversion)
```
./vendor/bin/img-opt <folder> [--max-width N] [--q-avif N] [--q-webp N] [--formats avif,webp] [--force] [--dry-run] [--cache-dir DIR]
```
- Converts PNG/JPG recursively, writing AVIF/WebP variants into `cache-dir` (defaults to `<folder>/_img-opt`).
- Skips fresh outputs unless `--force` is set. Use `--dry-run` to preview.

## Design notes
- AVIF/WebP capability is auto-detected from Imagick. If AVIF is missing, it falls back to WebP → JPEG/PNG.
- Cache keys include the source path + mtime + width + format + quality, so updates to the original regenerate variants.
- Width requests are clamped to avoid upscaling and can be capped via `max_width`.
- Minimal, maintained dependencies: Symfony Console/Filesystem/Finder; image work uses `ext-imagick`.

## Troubleshooting
- Ensure Imagick is built with WebP/AVIF: `php -r "var_dump((new Imagick())->queryFormats('WEBP'));"`
- If AVIF is unavailable, install `libheif` + rebuild Imagick (varies by distro). The library will automatically downgrade formats.***

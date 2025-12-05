<?php

declare(strict_types=1);

namespace ImgOpt;

use Imagick;
use ImagickException;

final class ImageProcessor
{
    private Capabilities $capabilities;
    private Config $config;

    public function __construct(Config $config, Capabilities $capabilities)
    {
        $this->config = $config;
        $this->capabilities = $capabilities;
    }

    /**
     * Generate a variant and write it to target path.
     *
     * @throws ImagickException
     */
    public function generate(string $source, int $targetWidth, string $format, string $targetPath, int $quality): void
    {
        if (!$this->capabilities->isImagickAvailable()) {
            throw new \RuntimeException('Imagick not available.');
        }

        $image = new Imagick($source);
        $image->setImageOrientation(Imagick::ORIENTATION_UNDEFINED);
        $image->stripImage();

        $resizeWidth = $targetWidth > 0 ? $targetWidth : 0;
        if ($resizeWidth > 0 && $image->getImageWidth() > $resizeWidth) {
            $image->resizeImage($resizeWidth, 0, Imagick::FILTER_LANCZOS, 1, true);
        }

        $this->encode($image, $format, $quality);
        $image->writeImage($targetPath);
        $image->destroy();
    }

    private function encode(Imagick $image, string $format, int $quality): void
    {
        $fmt = strtolower($format);
        $image->setImageFormat($fmt);

        switch ($fmt) {
            case 'avif':
                $image->setOption('heic:encode-alpha', 'true');
                $image->setImageCompressionQuality($quality);
                break;
            case 'webp':
                $image->setImageCompressionQuality($quality);
                $image->setOption('webp:method', '6');
                break;
            case 'jpeg':
            case 'jpg':
                $image->setImageCompressionQuality($quality);
                $image->setInterlaceScheme(Imagick::INTERLACE_JPEG);
                break;
            case 'png':
                // quality not always respected for PNG; keep defaults
                break;
            default:
                throw new \InvalidArgumentException("Unsupported format: {$format}");
        }
    }
}

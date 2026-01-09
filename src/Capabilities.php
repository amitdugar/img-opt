<?php

declare(strict_types=1);

namespace ImgOpt;

use Imagick;

final class Capabilities
{
    private bool $imagickAvailable;
    /** @var array<string,bool> */
    private array $formats = [];

    public function __construct()
    {
        $this->imagickAvailable = class_exists(Imagick::class);
        if ($this->imagickAvailable) {
            $this->loadFormats();
        }
    }

    public function isImagickAvailable(): bool
    {
        return $this->imagickAvailable;
    }

    public function supports(string $format): bool
    {
        $fmt = strtoupper($format);
        return $this->formats[$fmt] ?? false;
    }

    public function bestFormatForAccept(string $acceptHeader): string
    {
        $accept = strtolower($acceptHeader);
        if ($this->supports('AVIF') && ($accept === '' || str_contains($accept, 'image/avif'))) {
            return 'avif';
        }
        if ($this->supports('WEBP') && ($accept === '' || str_contains($accept, 'image/webp'))) {
            return 'webp';
        }

        return 'jpeg';
    }

    private function loadFormats(): void
    {
        try {
            $probe = new Imagick();
            $formats = $probe->queryFormats();
            foreach ($formats as $format) {
                $this->formats[$format] = true;
            }
        } catch (\Throwable $e) {
            $this->formats = [];
        }
    }
}

<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Contract;

interface ImageEffectsProcessorInterface
{
    public function optimize(string $source, string $destination, string $mimeType, int $quality): void;

    public function textWatermark(string $source, string $destination, string $text, string $position, int $opacity, int $scale, string $color, int $quality, ?int $x = null, ?int $y = null, string $font = 'interface'): void;

    public function imageWatermark(string $source, string $watermark, string $destination, string $position, int $opacity, int $scale, int $quality, ?int $x = null, ?int $y = null): void;
}

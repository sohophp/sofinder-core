<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Value;

final class InspectedFile
{
    public function __construct(
        public readonly int $size,
        public readonly string $mimeType,
        public readonly ?int $imageWidth = null,
        public readonly ?int $imageHeight = null,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Value;

final readonly class InspectedFile
{
    public function __construct(
        public int $size,
        public string $mimeType,
        public ?int $imageWidth = null,
        public ?int $imageHeight = null,
    ) {
    }
}

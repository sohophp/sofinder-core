<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Value;

final readonly class ImageProcessingLimits
{
    public function __construct(
        public int $maxWidth = 12_000,
        public int $maxHeight = 12_000,
        public int $maxSingleFramePixels = 50_000_000,
        public int $maxFrames = 200,
        public int $maxTotalPixels = 100_000_000,
        public int $memoryBytes = 268_435_456,
        public int $mapBytes = 536_870_912,
        public int $diskBytes = 1_073_741_824,
        public int $threads = 1,
        public int $timeoutSeconds = 30,
    ) {
    }
}

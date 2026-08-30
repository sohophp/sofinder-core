<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Value;

final class ImageProcessingLimits
{
    public function __construct(
        public readonly int $maxWidth = 12_000,
        public readonly int $maxHeight = 12_000,
        public readonly int $maxSingleFramePixels = 50_000_000,
        public readonly int $maxFrames = 200,
        public readonly int $maxTotalPixels = 100_000_000,
        public readonly int $memoryBytes = 268_435_456,
        public readonly int $mapBytes = 536_870_912,
        public readonly int $diskBytes = 1_073_741_824,
        public readonly int $threads = 1,
        public readonly int $timeoutSeconds = 30,
    ) {
    }
}

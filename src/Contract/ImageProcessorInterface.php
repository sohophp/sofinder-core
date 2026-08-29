<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Contract;

interface ImageProcessorInterface
{
    public function supports(string $mimeType): bool;

    /** @return array{width: int, height: int} */
    public function dimensions(string $source): array;

    /** @return array{width: int, height: int} */
    public function validate(string $source): array;

    public function isAnimated(string $source): bool;

    public function thumbnail(string $source, string $destination, int $width, int $height): void;

    public function transform(string $source, string $destination, int $rotation, int $width, int $height, int $quality = 88): void;

    public function crop(string $source, string $destination, int $x, int $y, int $width, int $height, int $quality = 88): void;
}

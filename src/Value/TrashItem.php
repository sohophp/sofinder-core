<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Value;

final class TrashItem implements \JsonSerializable
{
    public function __construct(
        public readonly string $id,
        public readonly string $resource,
        public readonly string $path,
        public readonly bool $directory,
        public readonly int $size,
        public readonly int $deletedAt,
        public readonly int $expiresAt,
    ) {
    }

    /** @return array<string, bool|int|string> */
    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }
}

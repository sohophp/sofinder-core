<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Value;

final readonly class TrashItem implements \JsonSerializable
{
    public function __construct(
        public string $id,
        public string $resource,
        public string $path,
        public bool $directory,
        public int $size,
        public int $deletedAt,
        public int $expiresAt,
    ) {
    }

    /** @return array<string, bool|int|string> */
    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }
}

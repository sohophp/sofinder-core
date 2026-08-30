<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Value;

final class Entry implements \JsonSerializable
{
    public function __construct(
        public readonly string $path,
        public readonly string $name,
        public readonly bool $directory,
        public readonly int $size,
        public readonly int $modifiedAt,
        public readonly ?string $mimeType = null,
        public readonly ?string $url = null,
        /** @var array<string, bool> */
        public readonly array $capabilities = [],
    ) {
    }

    /** @return array<string, array<string,bool>|bool|int|string|null> */
    public function jsonSerialize(): array
    {
        return [
            'path' => $this->path,
            'name' => $this->name,
            'directory' => $this->directory,
            'size' => $this->size,
            'modifiedAt' => $this->modifiedAt,
            'mimeType' => $this->mimeType,
            'url' => $this->url,
            'capabilities' => $this->capabilities,
        ];
    }
}

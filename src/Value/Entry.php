<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Value;

final readonly class Entry implements \JsonSerializable
{
    public function __construct(
        public string $path,
        public string $name,
        public bool $directory,
        public int $size,
        public int $modifiedAt,
        public ?string $mimeType = null,
        public ?string $url = null,
        /** @var array<string, bool> */
        public array $capabilities = [],
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

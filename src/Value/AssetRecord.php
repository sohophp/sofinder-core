<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Value;

final class AssetRecord implements \JsonSerializable
{
    /**
     * @param list<string> $tags
     * @param array<string,string> $altTranslations
     */
    public function __construct(
        public readonly string $id,
        public readonly string $workspace,
        public readonly string $resource,
        public readonly string $path,
        public readonly string $version,
        public readonly ?string $alt = null,
        public readonly ?string $title = null,
        public readonly array $tags = [],
        public readonly int $metadataVersion = 1,
        public readonly int $updatedAt = 0,
        public readonly bool $deleted = false,
        public readonly array $altTranslations = [],
    ) {
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return ['id' => $this->id, 'workspace' => $this->workspace, 'resource' => $this->resource, 'path' => $this->path, 'version' => $this->version, 'metadata' => $this->metadata(), 'deleted' => $this->deleted];
    }

    /** @return array{alt:?string,altTranslations:array<string,string>,title:?string,tags:list<string>,version:int,updatedAt:int} */
    public function metadata(): array { return ['alt' => $this->alt, 'altTranslations' => $this->altTranslations, 'title' => $this->title, 'tags' => $this->tags, 'version' => $this->metadataVersion, 'updatedAt' => $this->updatedAt]; }
}

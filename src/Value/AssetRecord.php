<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Value;

final readonly class AssetRecord implements \JsonSerializable
{
    /**
     * @param list<string> $tags
     * @param array<string,string> $altTranslations
     */
    public function __construct(
        public string $id,
        public string $workspace,
        public string $resource,
        public string $path,
        public string $version,
        public ?string $alt = null,
        public ?string $title = null,
        public array $tags = [],
        public int $metadataVersion = 1,
        public int $updatedAt = 0,
        public bool $deleted = false,
        public array $altTranslations = [],
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

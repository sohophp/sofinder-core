<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Contract;

use SohoPHP\SoFinder\Value\AssetRecord;
use SohoPHP\SoFinder\Value\Entry;

interface AssetCatalogInterface
{
    public function resolve(string $workspace, string $resource, string $path): ?AssetRecord;
    public function find(string $assetId): ?AssetRecord;
    public function register(string $workspace, string $resource, Entry $entry): AssetRecord;
    public function move(string $workspace, string $resource, string $source, string $destination): void;
    public function delete(string $workspace, string $resource, string $path, bool $retainIdentity = false): void;
    public function restore(string $workspace, string $resource, string $path): void;

    /** @param list<string> $tags */
    public function updateMetadata(string $assetId, ?string $alt, ?string $title, array $tags, int $expectedVersion): AssetRecord;
}

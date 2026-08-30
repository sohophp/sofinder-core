<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Asset;

use SohoPHP\SoFinder\Contract\AssetCatalogInterface;
use SohoPHP\SoFinder\Contract\LocalizedAssetMetadataCatalogInterface;
use SohoPHP\SoFinder\Contract\AtomicStateStoreInterface;
use SohoPHP\SoFinder\Exception\NotFoundException;
use SohoPHP\SoFinder\Exception\SoFinderException;
use SohoPHP\SoFinder\Value\AssetRecord;
use SohoPHP\SoFinder\Value\Entry;

final class SharedAssetCatalog implements AssetCatalogInterface, LocalizedAssetMetadataCatalogInterface
{
    public function __construct(private readonly AtomicStateStoreInterface $state)
    {
    }

    public function resolve(string $workspace, string $resource, string $path): ?AssetRecord
    {
        $data = $this->data(); $id = $data['paths'][$this->key($workspace, $resource, $path)] ?? null;
        return is_string($id) && is_array($data['assets'][$id] ?? null) ? $this->record($id, $data['assets'][$id]) : null;
    }

    public function find(string $assetId): ?AssetRecord
    {
        $data = $this->data(); return is_array($data['assets'][$assetId] ?? null) ? $this->record($assetId, $data['assets'][$assetId]) : null;
    }

    public function register(string $workspace, string $resource, Entry $entry): AssetRecord
    {
        $record = null;
        $this->mutate(function (array &$data) use ($workspace, $resource, $entry, &$record): void {
            $key = $this->key($workspace, $resource, $entry->path); $id = $data['paths'][$key] ?? null;
            if (!is_string($id) || !isset($data['assets'][$id])) $id = $this->uuid();
            $value = is_array($data['assets'][$id] ?? null) ? $data['assets'][$id] : ['alt' => null, 'altTranslations' => [], 'title' => null, 'tags' => [], 'metadataVersion' => 1, 'updatedAt' => time()];
            $value['workspace'] = $workspace; $value['resource'] = $resource; $value['path'] = $entry->path; $value['version'] = $entry->modifiedAt . '-' . $entry->size; $value['deleted'] = false;
            $data['paths'][$key] = $id; $data['assets'][$id] = $value; $record = $this->record($id, $value);
        });
        return $record ?? throw new SoFinderException('Unable to register the asset.', 'asset_catalog_failed', 500);
    }

    public function move(string $workspace, string $resource, string $source, string $destination): void
    {
        $this->mutate(function (array &$data) use ($workspace, $resource, $source, $destination): void { foreach ($data['assets'] as $id => &$asset) { if (!is_array($asset) || ($asset['workspace'] ?? null) !== $workspace || ($asset['resource'] ?? null) !== $resource || !is_string($asset['path'] ?? null) || ($asset['path'] !== $source && !str_starts_with($asset['path'], $source . '/'))) continue; $oldPath = $asset['path']; $newPath = $destination . substr($oldPath, strlen($source)); unset($data['paths'][$this->key($workspace, $resource, $oldPath)]); $data['paths'][$this->key($workspace, $resource, $newPath)] = $id; $asset['path'] = $newPath; $asset['updatedAt'] = time(); } unset($asset); });
    }

    public function delete(string $workspace, string $resource, string $path, bool $retainIdentity = false): void
    {
        $this->mutate(function (array &$data) use ($workspace, $resource, $path, $retainIdentity): void { foreach ($data['assets'] as &$asset) if (is_array($asset) && ($asset['workspace'] ?? null) === $workspace && ($asset['resource'] ?? null) === $resource && is_string($asset['path'] ?? null) && ($asset['path'] === $path || str_starts_with($asset['path'], $path . '/'))) { $asset['deleted'] = true; $asset['updatedAt'] = time(); if (!$retainIdentity) unset($data['paths'][$this->key($workspace, $resource, $asset['path'])]); } unset($asset); });
    }

    public function restore(string $workspace, string $resource, string $path): void
    {
        $this->mutate(function (array &$data) use ($workspace, $resource, $path): void { foreach ($data['assets'] as &$asset) if (is_array($asset) && ($asset['workspace'] ?? null) === $workspace && ($asset['resource'] ?? null) === $resource && is_string($asset['path'] ?? null) && ($asset['path'] === $path || str_starts_with($asset['path'], $path . '/'))) { $asset['deleted'] = false; $asset['updatedAt'] = time(); } unset($asset); });
    }

    public function updateMetadata(string $assetId, ?string $alt, ?string $title, array $tags, int $expectedVersion): AssetRecord
    {
        return $this->writeMetadata($assetId, $alt, $title, $tags, $expectedVersion, null);
    }

    public function updateLocalizedMetadata(string $assetId, ?string $alt, ?string $title, array $tags, int $expectedVersion, array $altTranslations): AssetRecord
    {
        return $this->writeMetadata($assetId, $alt, $title, $tags, $expectedVersion, $altTranslations);
    }

    /**
     * @param list<string> $tags
     * @param array<string,string>|null $altTranslations
     */
    private function writeMetadata(string $assetId, ?string $alt, ?string $title, array $tags, int $expectedVersion, ?array $altTranslations): AssetRecord
    {
        $record = null;
        $this->mutate(function (array &$data) use ($assetId, $alt, $title, $tags, $expectedVersion, $altTranslations, &$record): void {
            if (!is_array($data['assets'][$assetId] ?? null)) throw new NotFoundException('The asset does not exist.');
            $current = (int) ($data['assets'][$assetId]['metadataVersion'] ?? 1); if ($current !== $expectedVersion) throw new SoFinderException('The asset metadata was changed by another request.', 'asset_metadata_conflict', 409);
            $data['assets'][$assetId]['alt'] = $alt; $data['assets'][$assetId]['title'] = $title; $data['assets'][$assetId]['tags'] = $tags; if ($altTranslations !== null) $data['assets'][$assetId]['altTranslations'] = $altTranslations; $data['assets'][$assetId]['metadataVersion'] = $current + 1; $data['assets'][$assetId]['updatedAt'] = time(); $record = $this->record($assetId, $data['assets'][$assetId]);
        });
        return $record ?? throw new SoFinderException('Unable to update asset metadata.', 'asset_catalog_failed', 500);
    }

    /** @return array{assets:array<string,array<string,mixed>>,paths:array<string,string>} */
    private function data(): array { $data = $this->state->get('assets', 'catalog'); return ['assets' => is_array($data['assets'] ?? null) ? $data['assets'] : [], 'paths' => is_array($data['paths'] ?? null) ? $data['paths'] : []]; }
    /** @param \Closure(array<string,mixed>&):void $change */
    private function mutate(\Closure $change): void { $this->state->mutate('assets', 'catalog', function (array $data) use ($change): array { $data += ['assets' => [], 'paths' => []]; $change($data); return $data; }); }
    /** @param array<string,mixed> $v */
    private function record(string $id, array $v): AssetRecord { return new AssetRecord($id, (string) ($v['workspace'] ?? 'main'), (string) ($v['resource'] ?? ''), (string) ($v['path'] ?? ''), (string) ($v['version'] ?? ''), array_key_exists('alt', $v) && $v['alt'] !== null ? (string) $v['alt'] : null, array_key_exists('title', $v) && $v['title'] !== null ? (string) $v['title'] : null, array_values(array_filter((array) ($v['tags'] ?? []), 'is_string')), (int) ($v['metadataVersion'] ?? 1), (int) ($v['updatedAt'] ?? 0), (bool) ($v['deleted'] ?? false), array_filter((array) ($v['altTranslations'] ?? []), 'is_string')); }
    private function key(string $workspace, string $resource, string $path): string { return hash('sha256', $workspace . "\0" . $resource . "\0" . $path); }
    private function uuid(): string { $b = random_bytes(16); $b[6] = chr((ord($b[6]) & 0x0f) | 0x40); $b[8] = chr((ord($b[8]) & 0x3f) | 0x80); return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4)); }
}

<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Asset;

use SohoPHP\SoFinder\Contract\AssetCatalogInterface;
use SohoPHP\SoFinder\Contract\LocalizedAssetMetadataCatalogInterface;
use SohoPHP\SoFinder\Exception\NotFoundException;
use SohoPHP\SoFinder\Exception\SoFinderException;
use SohoPHP\SoFinder\Value\AssetRecord;
use SohoPHP\SoFinder\Value\Entry;

final class JsonAssetCatalog implements AssetCatalogInterface, LocalizedAssetMetadataCatalogInterface
{
    public function __construct(private readonly string $file)
    {
    }

    public function resolve(string $workspace, string $resource, string $path): ?AssetRecord
    {
        $data = $this->read();
        $id = $data['paths'][$this->key($workspace, $resource, $path)] ?? null;
        return is_string($id) && isset($data['assets'][$id]) && is_array($data['assets'][$id]) ? $this->record($id, $data['assets'][$id]) : null;
    }

    public function find(string $assetId): ?AssetRecord
    {
        $data = $this->read();
        return isset($data['assets'][$assetId]) && is_array($data['assets'][$assetId]) ? $this->record($assetId, $data['assets'][$assetId]) : null;
    }

    public function register(string $workspace, string $resource, Entry $entry): AssetRecord
    {
        return $this->mutate(function (array &$data) use ($workspace, $resource, $entry): AssetRecord {
            $key = $this->key($workspace, $resource, $entry->path);
            $id = $data['paths'][$key] ?? null;
            if (!is_string($id) || !isset($data['assets'][$id])) $id = $this->uuid();
            $existing = is_array($data['assets'][$id] ?? null) ? $data['assets'][$id] : [];
            $data['paths'][$key] = $id;
            $data['assets'][$id] = $existing + ['workspace' => $workspace, 'resource' => $resource, 'path' => $entry->path, 'alt' => null, 'altTranslations' => [], 'title' => null, 'tags' => [], 'metadataVersion' => 1, 'updatedAt' => time()];
            $data['assets'][$id]['workspace'] = $workspace; $data['assets'][$id]['resource'] = $resource; $data['assets'][$id]['path'] = $entry->path;
            $data['assets'][$id]['version'] = $entry->modifiedAt . '-' . $entry->size; $data['assets'][$id]['deleted'] = false;
            return $this->record($id, $data['assets'][$id]);
        });
    }

    public function move(string $workspace, string $resource, string $source, string $destination): void
    {
        $this->mutate(function (array &$data) use ($workspace, $resource, $source, $destination): mixed {
            foreach ($data['assets'] as $id => &$asset) {
                if (!is_array($asset) || ($asset['workspace'] ?? null) !== $workspace || ($asset['resource'] ?? null) !== $resource || !is_string($asset['path'] ?? null) || ($asset['path'] !== $source && !str_starts_with($asset['path'], $source . '/'))) continue;
                $oldPath = $asset['path']; $newPath = $destination . substr($oldPath, strlen($source)); unset($data['paths'][$this->key($workspace, $resource, $oldPath)]); $data['paths'][$this->key($workspace, $resource, $newPath)] = $id; $asset['path'] = $newPath; $asset['updatedAt'] = time();
            }
            unset($asset);
            return null;
        });
    }

    public function delete(string $workspace, string $resource, string $path, bool $retainIdentity = false): void
    {
        $this->mutate(function (array &$data) use ($workspace, $resource, $path, $retainIdentity): mixed {
            foreach ($data['assets'] as &$asset) if (is_array($asset) && ($asset['workspace'] ?? null) === $workspace && ($asset['resource'] ?? null) === $resource && is_string($asset['path'] ?? null) && ($asset['path'] === $path || str_starts_with($asset['path'], $path . '/'))) { $asset['deleted'] = true; $asset['updatedAt'] = time(); if (!$retainIdentity) unset($data['paths'][$this->key($workspace, $resource, $asset['path'])]); }
            unset($asset);
            return null;
        });
    }

    public function restore(string $workspace, string $resource, string $path): void
    {
        $this->mutate(function (array &$data) use ($workspace, $resource, $path): mixed {
            foreach ($data['assets'] as &$asset) if (is_array($asset) && ($asset['workspace'] ?? null) === $workspace && ($asset['resource'] ?? null) === $resource && is_string($asset['path'] ?? null) && ($asset['path'] === $path || str_starts_with($asset['path'], $path . '/'))) { $asset['deleted'] = false; $asset['updatedAt'] = time(); }
            unset($asset);
            return null;
        });
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
        return $this->mutate(function (array &$data) use ($assetId, $alt, $title, $tags, $expectedVersion, $altTranslations): AssetRecord {
            if (!isset($data['assets'][$assetId]) || !is_array($data['assets'][$assetId])) throw new NotFoundException('The asset does not exist.');
            $current = (int) ($data['assets'][$assetId]['metadataVersion'] ?? 1);
            if ($current !== $expectedVersion) throw new SoFinderException('The asset metadata was changed by another request.', 'asset_metadata_conflict', 409);
            $data['assets'][$assetId]['alt'] = $alt; $data['assets'][$assetId]['title'] = $title; $data['assets'][$assetId]['tags'] = $tags;
            if ($altTranslations !== null) $data['assets'][$assetId]['altTranslations'] = $altTranslations;
            $data['assets'][$assetId]['metadataVersion'] = $current + 1; $data['assets'][$assetId]['updatedAt'] = time();
            return $this->record($assetId, $data['assets'][$assetId]);
        });
    }

    /** @return array{assets:array<string,array<string,mixed>>,paths:array<string,string>} */
    private function read(): array
    {
        if (!is_file($this->file)) return ['assets' => [], 'paths' => []];
        try { $data = json_decode((string) file_get_contents($this->file), true, 512, JSON_THROW_ON_ERROR); } catch (\JsonException $e) { throw new SoFinderException('The asset catalog is corrupted.', 'asset_catalog_failed', 500, $e); }
        return is_array($data) ? ['assets' => is_array($data['assets'] ?? null) ? $data['assets'] : [], 'paths' => is_array($data['paths'] ?? null) ? $data['paths'] : []] : ['assets' => [], 'paths' => []];
    }

    /** @param \Closure(array<string,mixed>&):mixed $callback */
    private function mutate(\Closure $callback): mixed
    {
        $directory = dirname($this->file); if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) throw new SoFinderException('Unable to create the asset catalog directory.', 'asset_catalog_failed', 500);
        $lock = fopen($this->file . '.lock', 'c+'); if ($lock === false || !flock($lock, LOCK_EX)) throw new SoFinderException('Unable to lock the asset catalog.', 'asset_catalog_failed', 500);
        try { $data = $this->read(); $result = $callback($data); $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES); $temporary = tempnam($directory, '.assets-'); if ($temporary === false || file_put_contents($temporary, $json, LOCK_EX) === false || !rename($temporary, $this->file)) throw new SoFinderException('Unable to save the asset catalog.', 'asset_catalog_failed', 500); return $result; }
        finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    /** @param array<string,mixed> $value */
    private function record(string $id, array $value): AssetRecord
    {
        return new AssetRecord($id, (string) ($value['workspace'] ?? 'main'), (string) ($value['resource'] ?? ''), (string) ($value['path'] ?? ''), (string) ($value['version'] ?? ''), array_key_exists('alt', $value) && $value['alt'] !== null ? (string) $value['alt'] : null, array_key_exists('title', $value) && $value['title'] !== null ? (string) $value['title'] : null, array_values(array_filter((array) ($value['tags'] ?? []), 'is_string')), (int) ($value['metadataVersion'] ?? 1), (int) ($value['updatedAt'] ?? 0), (bool) ($value['deleted'] ?? false), array_filter((array) ($value['altTranslations'] ?? []), 'is_string'));
    }

    private function key(string $workspace, string $resource, string $path): string { return hash('sha256', $workspace . "\0" . $resource . "\0" . $path); }
    private function uuid(): string { $b = random_bytes(16); $b[6] = chr((ord($b[6]) & 0x0f) | 0x40); $b[8] = chr((ord($b[8]) & 0x3f) | 0x80); return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4)); }
}

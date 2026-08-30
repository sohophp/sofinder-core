<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Maintenance;

use SohoPHP\SoFinder\Exception\SoFinderException;
use SohoPHP\SoFinder\Contract\StorageAdapterInterface;
use SohoPHP\SoFinder\ResourceRegistry;

final class MetadataRepairer
{
    public function __construct(
        private readonly string $file,
        private readonly ResourceRegistry $resources,
        private readonly bool $localStoreActive = true,
    ) {
    }

    /** @return array{supported:bool,dryRun:bool,changed:bool,users:int,resources:int,removed:int} */
    public function repair(bool $dryRun): array
    {
        if (!$this->localStoreActive) return ['supported' => false, 'dryRun' => $dryRun, 'changed' => false, 'users' => 0, 'resources' => 0, 'removed' => 0];
        if (!is_file($this->file)) return ['supported' => true, 'dryRun' => $dryRun, 'changed' => false, 'users' => 0, 'resources' => 0, 'removed' => 0];
        $lock = @fopen($this->file . '.lock', 'c+b');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) fclose($lock);
            throw new SoFinderException('Unable to lock the metadata store for repair.', 'metadata_store_failed', 500);
        }
        try {
            $contents = @file_get_contents($this->file);
            try { $data = is_string($contents) ? json_decode($contents, true, 512, JSON_THROW_ON_ERROR) : null; }
            catch (\JsonException $exception) { throw new SoFinderException('The metadata store is corrupted; restore it from backup before repair.', 'metadata_store_failed', 500, $exception); }
            if (!is_array($data)) throw new SoFinderException('The metadata store is invalid.', 'metadata_store_failed', 500);
            $before = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $removed = 0;
            $users = is_array($data['users'] ?? null) ? $data['users'] : [];
            $normalizedUsers = [];
            $resourceCount = 0;
            foreach ($users as $actor => $actorResources) {
                if (!is_string($actor) || $actor === '' || !is_array($actorResources)) { ++$removed; continue; }
                foreach ($actorResources as $resourceName => $metadata) {
                    if (!is_string($resourceName) || !is_array($metadata)) { ++$removed; continue; }
                    try { $resource = $this->resources->get($resourceName); }
                    catch (SoFinderException) { ++$removed; continue; }
                    ++$resourceCount;
                    $favorites = $this->paths((array) ($metadata['favorites'] ?? []), $resource->storage, 500, $removed);
                    $quickAccess = $this->paths((array) ($metadata['quickAccess'] ?? []), $resource->storage, 12, $removed);
                    $tags = [];
                    foreach ((array) ($metadata['tags'] ?? []) as $path => $values) {
                        if (!is_string($path) || !$this->exists($resource->storage, $path) || !is_array($values)) { ++$removed; continue; }
                        $clean = [];
                        foreach ($values as $tag) {
                            if (!is_string($tag)) { ++$removed; continue; }
                            $tag = trim($tag);
                            if ($tag === '' || mb_strlen($tag) > 30 || preg_match('/[\x00-\x1F\x7F]/u', $tag) === 1) { ++$removed; continue; }
                            $clean[mb_strtolower($tag)] = $tag;
                        }
                        if ($clean !== []) $tags[$path] = array_slice(array_values($clean), 0, 10);
                    }
                    $recent = [];
                    foreach ((array) ($metadata['recent'] ?? []) as $item) {
                        if (!is_array($item) || !is_string($item['path'] ?? null) || !is_int($item['touchedAt'] ?? null) || !$this->exists($resource->storage, $item['path'])) { ++$removed; continue; }
                        if (!isset($recent[$item['path']])) $recent[$item['path']] = ['path' => $item['path'], 'touchedAt' => $item['touchedAt']];
                    }
                    $normalizedUsers[$actor][$resourceName] = ['favorites' => $favorites, 'quickAccess' => $quickAccess, 'tags' => $tags, 'recent' => array_slice(array_values($recent), 0, 50)];
                }
            }
            $normalized = ['version' => 1, 'users' => $normalizedUsers];
            $after = json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $changed = $before !== $after;
            if ($changed && !$dryRun) $this->write($normalized);

            return ['supported' => true, 'dryRun' => $dryRun, 'changed' => $changed, 'users' => count($normalizedUsers), 'resources' => $resourceCount, 'removed' => $removed];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @param array<mixed,mixed> $values
     * @return list<string>
     */
    private function paths(array $values, StorageAdapterInterface $storage, int $limit, int &$removed, bool $directoriesOnly = false): array
    {
        $result = [];
        foreach ($values as $path) {
            if (!is_string($path) || isset($result[$path])) { ++$removed; continue; }
            try { $entry = $storage->entry($path); }
            catch (\Throwable) { ++$removed; continue; }
            if ($directoriesOnly && !$entry->directory) { ++$removed; continue; }
            $result[$path] = $path;
        }
        $result = array_values($result);
        if (count($result) > $limit) $removed += count($result) - $limit;
        return array_slice($result, 0, $limit);
    }

    private function exists(StorageAdapterInterface $storage, string $path): bool
    {
        try { $storage->entry($path); return true; } catch (\Throwable) { return false; }
    }

    /** @param array<string,mixed> $data */
    private function write(array $data): void
    {
        $temporary = tempnam(dirname($this->file), '.sofinder-metadata-repair-');
        if ($temporary === false || file_put_contents($temporary, json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), LOCK_EX) === false || !@rename($temporary, $this->file)) {
            if (is_string($temporary)) @unlink($temporary);
            throw new SoFinderException('Unable to save repaired metadata.', 'metadata_store_failed', 500);
        }
        @chmod($this->file, 0660);
    }
}

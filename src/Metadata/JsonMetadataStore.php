<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Metadata;

use SohoPHP\SoFinder\Contract\QuickAccessMetadataStoreInterface;
use SohoPHP\SoFinder\Exception\SoFinderException;

final readonly class JsonMetadataStore implements QuickAccessMetadataStoreInterface
{
    public function __construct(private string $file)
    {
    }

    public function get(string $actor, string $resource): array
    {
        $data = $this->read();
        $metadata = $data['users'][$actor][$resource] ?? [];

        return [
            'favorites' => array_values(array_filter((array) ($metadata['favorites'] ?? []), 'is_string')),
            'quickAccess' => array_slice(array_values(array_filter((array) ($metadata['quickAccess'] ?? []), 'is_string')), 0, 12),
            'tags' => $this->normalizeStoredTags((array) ($metadata['tags'] ?? [])),
            'recent' => array_values(array_filter((array) ($metadata['recent'] ?? []), static fn (mixed $item): bool => is_array($item) && isset($item['path'], $item['touchedAt']) && is_string($item['path']) && is_int($item['touchedAt']))),
        ];
    }

    public function setQuickAccess(string $actor, string $resource, string $path, bool $pinned): void
    {
        $this->mutate(function (array &$data) use ($actor, $resource, $path, $pinned): void {
            $paths = array_values(array_diff(array_filter((array) ($data['users'][$actor][$resource]['quickAccess'] ?? []), 'is_string'), [$path]));
            if ($pinned) array_unshift($paths, $path);
            $data['users'][$actor][$resource]['quickAccess'] = array_slice($paths, 0, 12);
        });
    }

    public function setFavorite(string $actor, string $resource, string $path, bool $favorite): void
    {
        $this->mutate(function (array &$data) use ($actor, $resource, $path, $favorite): void {
            $favorites = array_values(array_filter((array) ($data['users'][$actor][$resource]['favorites'] ?? []), 'is_string'));
            $favorites = array_values(array_diff($favorites, [$path]));
            if ($favorite) {
                array_unshift($favorites, $path);
            }
            $data['users'][$actor][$resource]['favorites'] = array_slice($favorites, 0, 500);
        });
    }

    public function setTags(string $actor, string $resource, string $path, array $tags): void
    {
        $this->mutate(function (array &$data) use ($actor, $resource, $path, $tags): void {
            if ($tags === []) {
                unset($data['users'][$actor][$resource]['tags'][$path]);
            } else {
                $data['users'][$actor][$resource]['tags'][$path] = $tags;
            }
        });
    }

    public function touch(string $actor, string $resource, string $path, int $timestamp): void
    {
        $this->mutate(function (array &$data) use ($actor, $resource, $path, $timestamp): void {
            $recent = array_values(array_filter(
                (array) ($data['users'][$actor][$resource]['recent'] ?? []),
                static fn (mixed $item): bool => is_array($item) && ($item['path'] ?? null) !== $path,
            ));
            array_unshift($recent, ['path' => $path, 'touchedAt' => $timestamp]);
            $data['users'][$actor][$resource]['recent'] = array_slice($recent, 0, 50);
        });
    }

    public function movePath(string $actor, string $resource, string $source, string $destination): void
    {
        $this->mutate(function (array &$data) use ($actor, $resource, $source, $destination): void {
            $metadata = &$data['users'][$actor][$resource];
            $replace = static fn (string $path): string => $path === $source
                ? $destination
                : (str_starts_with($path, $source . '/') ? $destination . substr($path, strlen($source)) : $path);
            $metadata['favorites'] = array_values(array_unique(array_map($replace, array_filter((array) ($metadata['favorites'] ?? []), 'is_string'))));
            $metadata['quickAccess'] = array_slice(array_values(array_unique(array_map($replace, array_filter((array) ($metadata['quickAccess'] ?? []), 'is_string')))), 0, 12);
            $tags = [];
            foreach ((array) ($metadata['tags'] ?? []) as $path => $values) {
                if (is_string($path)) {
                    $tags[$replace($path)] = $values;
                }
            }
            $metadata['tags'] = $tags;
            foreach ((array) ($metadata['recent'] ?? []) as $index => $item) {
                if (is_array($item) && is_string($item['path'] ?? null)) {
                    $metadata['recent'][$index]['path'] = $replace($item['path']);
                }
            }
        });
    }

    public function deletePath(string $actor, string $resource, string $path): void
    {
        $this->mutate(function (array &$data) use ($actor, $resource, $path): void {
            $metadata = &$data['users'][$actor][$resource];
            $matches = static fn (string $candidate): bool => $candidate === $path || str_starts_with($candidate, $path . '/');
            $metadata['favorites'] = array_values(array_filter(
                (array) ($metadata['favorites'] ?? []),
                static fn (mixed $favorite): bool => is_string($favorite) && !$matches($favorite),
            ));
            $metadata['quickAccess'] = array_values(array_filter(
                (array) ($metadata['quickAccess'] ?? []),
                static fn (mixed $item): bool => is_string($item) && !$matches($item),
            ));
            foreach (array_keys((array) ($metadata['tags'] ?? [])) as $tagPath) {
                if (is_string($tagPath) && $matches($tagPath)) {
                    unset($metadata['tags'][$tagPath]);
                }
            }
            $metadata['recent'] = array_values(array_filter(
                (array) ($metadata['recent'] ?? []),
                static fn (mixed $item): bool => is_array($item) && is_string($item['path'] ?? null) && !$matches($item['path']),
            ));
        });
    }

    /** @return array<string, mixed> */
    private function read(): array
    {
        if (!is_file($this->file)) {
            return ['version' => 1, 'users' => []];
        }
        $contents = @file_get_contents($this->file);
        try {
            $data = $contents === false ? null : json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new SoFinderException('The metadata store is corrupted.', 'metadata_store_failed', 500, $exception);
        }
        if (!is_array($data)) {
            throw new SoFinderException('The metadata store is invalid.', 'metadata_store_failed', 500);
        }

        return $data;
    }

    /** @param callable(array<string, mixed>&):void $operation */
    private function mutate(callable $operation): void
    {
        $directory = dirname($this->file);
        if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new SoFinderException('Unable to create the metadata directory.', 'metadata_store_failed', 500);
        }
        $lock = fopen($this->file . '.lock', 'c+b');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) fclose($lock);
            throw new SoFinderException('Unable to lock the metadata store.', 'metadata_store_failed', 500);
        }
        try {
            $data = $this->read();
            $operation($data);
            $temporary = tempnam($directory, '.sofinder-metadata-');
            if ($temporary === false || file_put_contents($temporary, json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), LOCK_EX) === false || !@rename($temporary, $this->file)) {
                if (is_string($temporary)) @unlink($temporary);
                throw new SoFinderException('Unable to save the metadata store.', 'metadata_store_failed', 500);
            }
            @chmod($this->file, 0660);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @param array<string, mixed> $tags
     * @return array<string, list<string>>
     */
    private function normalizeStoredTags(array $tags): array
    {
        $normalized = [];
        foreach ($tags as $path => $values) {
            if (is_array($values)) {
                $normalized[$path] = array_values(array_filter($values, 'is_string'));
            }
        }

        return $normalized;
    }
}

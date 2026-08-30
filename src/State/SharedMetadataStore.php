<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\State;

use SohoPHP\SoFinder\Contract\AtomicStateStoreInterface;
use SohoPHP\SoFinder\Contract\QuickAccessMetadataStoreInterface;

final class SharedMetadataStore implements QuickAccessMetadataStoreInterface
{
    public function __construct(private readonly AtomicStateStoreInterface $state)
    {
    }

    public function get(string $actor, string $resource): array
    {
        $metadata = $this->state->get('metadata', $actor . "\0" . $resource);

        return [
            'favorites' => array_values(array_filter((array) ($metadata['favorites'] ?? []), 'is_string')),
            'quickAccess' => array_slice(array_values(array_filter((array) ($metadata['quickAccess'] ?? []), 'is_string')), 0, 12),
            'tags' => $this->tags((array) ($metadata['tags'] ?? [])),
            'recent' => array_values(array_filter((array) ($metadata['recent'] ?? []), static fn (mixed $item): bool => is_array($item) && is_string($item['path'] ?? null) && is_int($item['touchedAt'] ?? null))),
        ];
    }

    public function setQuickAccess(string $actor, string $resource, string $path, bool $pinned): void
    {
        $this->change($actor, $resource, static function (array $data) use ($path, $pinned): array {
            $paths = array_values(array_diff(array_filter((array) ($data['quickAccess'] ?? []), 'is_string'), [$path]));
            if ($pinned) array_unshift($paths, $path);
            $data['quickAccess'] = array_slice($paths, 0, 12);
            return $data;
        });
    }

    public function setFavorite(string $actor, string $resource, string $path, bool $favorite): void
    {
        $this->change($actor, $resource, static function (array $data) use ($path, $favorite): array {
            $favorites = array_values(array_diff(array_filter((array) ($data['favorites'] ?? []), 'is_string'), [$path]));
            if ($favorite) array_unshift($favorites, $path);
            $data['favorites'] = array_slice($favorites, 0, 500);
            return $data;
        });
    }

    public function setTags(string $actor, string $resource, string $path, array $tags): void
    {
        $this->change($actor, $resource, static function (array $data) use ($path, $tags): array {
            if ($tags === []) unset($data['tags'][$path]); else $data['tags'][$path] = $tags;
            return $data;
        });
    }

    public function touch(string $actor, string $resource, string $path, int $timestamp): void
    {
        $this->change($actor, $resource, static function (array $data) use ($path, $timestamp): array {
            $recent = array_values(array_filter((array) ($data['recent'] ?? []), static fn (mixed $item): bool => is_array($item) && ($item['path'] ?? null) !== $path));
            array_unshift($recent, ['path' => $path, 'touchedAt' => $timestamp]);
            $data['recent'] = array_slice($recent, 0, 50);
            return $data;
        });
    }

    public function movePath(string $actor, string $resource, string $source, string $destination): void
    {
        $this->change($actor, $resource, static function (array $data) use ($source, $destination): array {
            $replace = static fn (string $path): string => $path === $source ? $destination : (str_starts_with($path, $source . '/') ? $destination . substr($path, strlen($source)) : $path);
            $data['favorites'] = array_values(array_unique(array_map($replace, array_filter((array) ($data['favorites'] ?? []), 'is_string'))));
            $data['quickAccess'] = array_slice(array_values(array_unique(array_map($replace, array_filter((array) ($data['quickAccess'] ?? []), 'is_string')))), 0, 12);
            $tags = [];
            foreach ((array) ($data['tags'] ?? []) as $path => $values) if (is_string($path)) $tags[$replace($path)] = $values;
            $data['tags'] = $tags;
            foreach ((array) ($data['recent'] ?? []) as $index => $item) if (is_array($item) && is_string($item['path'] ?? null)) $data['recent'][$index]['path'] = $replace($item['path']);
            return $data;
        });
    }

    public function deletePath(string $actor, string $resource, string $path): void
    {
        $this->change($actor, $resource, static function (array $data) use ($path): array {
            $matches = static fn (string $candidate): bool => $candidate === $path || str_starts_with($candidate, $path . '/');
            $data['favorites'] = array_values(array_filter((array) ($data['favorites'] ?? []), static fn (mixed $item): bool => is_string($item) && !$matches($item)));
            $data['quickAccess'] = array_values(array_filter((array) ($data['quickAccess'] ?? []), static fn (mixed $item): bool => is_string($item) && !$matches($item)));
            foreach (array_keys((array) ($data['tags'] ?? [])) as $tagPath) if (is_string($tagPath) && $matches($tagPath)) unset($data['tags'][$tagPath]);
            $data['recent'] = array_values(array_filter((array) ($data['recent'] ?? []), static fn (mixed $item): bool => is_array($item) && is_string($item['path'] ?? null) && !$matches($item['path'])));
            return $data;
        });
    }

    /** @param callable(array<string,mixed>):array<string,mixed> $callback */
    private function change(string $actor, string $resource, callable $callback): void
    {
        $this->state->mutate('metadata', $actor . "\0" . $resource, $callback);
    }

    /**
     * @param array<string,mixed> $values
     * @return array<string,list<string>>
     */
    private function tags(array $values): array
    {
        $result = [];
        foreach ($values as $path => $tags) if (is_string($path) && is_array($tags)) $result[$path] = array_values(array_filter($tags, 'is_string'));
        return $result;
    }
}

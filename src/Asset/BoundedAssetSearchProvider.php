<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Asset;

use SohoPHP\SoFinder\Contract\AssetCatalogInterface;
use SohoPHP\SoFinder\Contract\AssetSearchProviderInterface;
use SohoPHP\SoFinder\Exception\SoFinderException;
use SohoPHP\SoFinder\FileManager;
use SohoPHP\SoFinder\Value\AssetRecord;
use SohoPHP\SoFinder\Value\AssetSearchQuery;
use SohoPHP\SoFinder\Value\AssetSearchResult;
use SohoPHP\SoFinder\Value\Entry;
use SohoPHP\SoFinder\Value\WorkspaceContext;

/** Safe fallback for installations without an indexed search plugin. */
final class BoundedAssetSearchProvider implements AssetSearchProviderInterface
{
    public function __construct(
        private readonly FileManager $files,
        private readonly AssetCatalogInterface $catalog,
        private readonly int $maximumScannedEntries = 10000,
    ) {
        if ($this->maximumScannedEntries < 100 || $this->maximumScannedEntries > 100000) {
            throw new \InvalidArgumentException('The asset search scan limit must be between 100 and 100000.');
        }
    }

    public function search(WorkspaceContext $workspace, AssetSearchQuery $query): AssetSearchResult
    {
        $visibleResources = array_column($this->files->resources(), 'name');
        $requested = $query->resources === [] ? $visibleResources : array_values(array_intersect($query->resources, $visibleResources));
        $requested = array_values(array_filter($requested, fn (string $resource): bool => $workspace->resources === [] || in_array($resource, $workspace->resources, true)));
        $matches = [];
        $facets = ['resources' => [], 'types' => [], 'extensions' => []];
        $scanned = 0;
        $truncated = false;

        foreach ($requested as $resource) {
            $directories = [$query->path];
            while ($directories !== []) {
                $directory = array_shift($directories);
                if (!is_string($directory)) continue;
                $cursor = null;
                $offset = 0;
                do {
                    $page = $this->files->list($resource, $directory, '', 'name', 'asc', $offset, 500, cursor: $cursor);
                    foreach ($page['entries'] as $entry) {
                        if (++$scanned > $this->maximumScannedEntries) { $truncated = true; break 4; }
                        if ($entry->directory) { $directories[] = $entry->path; continue; }
                        $record = $this->catalog->resolve($workspace->id, $resource, $entry->path);
                        if (!$this->matches($entry, $record, $query)) continue;
                        $type = $this->type($entry);
                        $extension = strtolower((string) pathinfo($entry->name, PATHINFO_EXTENSION));
                        $facets['resources'][$resource] = ($facets['resources'][$resource] ?? 0) + 1;
                        $facets['types'][$type] = ($facets['types'][$type] ?? 0) + 1;
                        if ($extension !== '') $facets['extensions'][$extension] = ($facets['extensions'][$extension] ?? 0) + 1;
                        $matches[] = ['resource' => $resource, 'entry' => $entry, 'assetId' => $record?->id, 'metadata' => $record?->metadata() ?? $this->emptyMetadata()];
                    }
                    $count = count($page['entries']);
                    $cursor = $page['nextCursor'];
                    $offset += $count;
                    if ($count === 0) break;
                } while ($cursor !== null || ($page['total'] !== null && $offset < $page['total']));
            }
        }

        usort($matches, static function (array $left, array $right): int {
            $modified = $right['entry']->modifiedAt <=> $left['entry']->modifiedAt;
            return $modified !== 0 ? $modified : strnatcasecmp($left['entry']->name, $right['entry']->name);
        });
        foreach ($facets as &$values) { arsort($values); }
        unset($values);

        return new AssetSearchResult(array_slice($matches, $query->offset, $query->limit), count($matches), $query->offset, $query->limit, min($scanned, $this->maximumScannedEntries), $truncated, $facets);
    }

    private function matches(Entry $entry, ?AssetRecord $record, AssetSearchQuery $query): bool
    {
        if ($query->minimumSize !== null && $entry->size < $query->minimumSize) return false;
        if ($query->maximumSize !== null && $entry->size > $query->maximumSize) return false;
        if ($query->modifiedAfter !== null && $entry->modifiedAt < $query->modifiedAfter) return false;
        if ($query->modifiedBefore !== null && $entry->modifiedAt > $query->modifiedBefore) return false;
        $extension = strtolower((string) pathinfo($entry->name, PATHINFO_EXTENSION));
        if ($query->extensions !== [] && !in_array($extension, $query->extensions, true)) return false;
        if ($query->type !== 'all' && $this->type($entry) !== $query->type) return false;
        $metadata = $record?->metadata() ?? $this->emptyMetadata();
        if ($query->tags !== []) {
            $tags = array_map('mb_strtolower', $metadata['tags']);
            foreach ($query->tags as $tag) if (!in_array(mb_strtolower($tag), $tags, true)) return false;
        }
        $keyword = mb_strtolower(trim($query->keyword));
        if ($keyword === '') return true;
        $values = [];
        if (in_array('name', $query->fields, true)) $values[] = $entry->name;
        if (in_array('title', $query->fields, true)) $values[] = $metadata['title'] ?? '';
        if (in_array('alt', $query->fields, true)) $values = [...$values, $metadata['alt'] ?? '', ...array_values($metadata['altTranslations'])];
        if (in_array('tags', $query->fields, true)) $values = [...$values, ...$metadata['tags']];
        foreach ($values as $value) if (str_contains(mb_strtolower((string) $value), $keyword)) return true;
        return false;
    }

    private function type(Entry $entry): string
    {
        $mime = strtolower($entry->mimeType ?? '');
        $extension = strtolower((string) pathinfo($entry->name, PATHINFO_EXTENSION));
        if (str_starts_with($mime, 'image/') || in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'heic'], true)) return 'image';
        if (str_starts_with($mime, 'audio/') || in_array($extension, ['mp3', 'wav', 'ogg', 'm4a', 'flac'], true)) return 'audio';
        if (str_starts_with($mime, 'video/') || in_array($extension, ['mp4', 'webm', 'mov', 'avi', 'mkv'], true)) return 'video';
        if (in_array($extension, ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'txt', 'md'], true)) return 'document';
        if (in_array($extension, ['zip', 'rar', '7z', 'tar', 'gz', 'bz2'], true)) return 'archive';
        return 'other';
    }

    /** @return array{alt:null,altTranslations:array<string,string>,title:null,tags:list<string>,version:int,updatedAt:int} */
    private function emptyMetadata(): array
    {
        return ['alt' => null, 'altTranslations' => [], 'title' => null, 'tags' => [], 'version' => 0, 'updatedAt' => 0];
    }
}

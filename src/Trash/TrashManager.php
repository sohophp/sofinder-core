<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Trash;

use SohoPHP\SoFinder\Contract\ActorProviderInterface;
use SohoPHP\SoFinder\Contract\LocalPathProviderInterface;
use SohoPHP\SoFinder\Contract\RecycleBinInterface;
use SohoPHP\SoFinder\Exception\ConflictException;
use SohoPHP\SoFinder\Exception\NotFoundException;
use SohoPHP\SoFinder\Exception\SoFinderException;
use SohoPHP\SoFinder\Security\PathGuard;
use SohoPHP\SoFinder\Value\Entry;
use SohoPHP\SoFinder\Value\ResourceStorage;
use SohoPHP\SoFinder\Value\TrashItem;

final class TrashManager implements RecycleBinInterface
{
    public function __construct(
        private readonly string $root,
        private readonly ActorProviderInterface $actors,
        private readonly PathGuard $paths,
        private readonly int $retentionDays = 30,
        private readonly int $maxItems = 1000,
        private readonly int $maxBytes = 1073741824,
    ) {
    }

    /** @return array{item:TrashItem,purgedItems:int,purgedBytes:int} */
    public function put(ResourceStorage $resource, string $path): array
    {
        $path = $this->paths->normalize($path);
        if ($path === '') {
            throw new SoFinderException('The storage root cannot be moved to trash.', 'invalid_path', 400);
        }
        $entry = $resource->storage->entry($path);
        if (!$resource->storage instanceof LocalPathProviderInterface) {
            throw new SoFinderException('This storage adapter does not support recoverable deletion.', 'trash_unsupported', 501);
        }
        $source = $resource->storage->absolutePath($path);
        $this->assertTreeHasNoLinks($source);

        $actorRoot = $this->actorRoot();
        $lock = @fopen($actorRoot . '/.trash.lock', 'c+b');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new SoFinderException('Unable to lock the private trash directory.', 'trash_unavailable', 500);
        }
        try {
            return $this->putLocked($resource, $path, $source, $entry);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array{usedItems:int,usedBytes:int,maxItems:int,maxBytes:int} */
    public function statistics(): array
    {
        $items = $this->list();

        return [
            'usedItems' => count($items),
            'usedBytes' => array_sum(array_map(static fn (TrashItem $item): int => $item->size, $items)),
            'maxItems' => $this->maxItems,
            'maxBytes' => $this->maxBytes,
        ];
    }

    /** @return array{item:TrashItem,purgedItems:int,purgedBytes:int} */
    private function putLocked(ResourceStorage $resource, string $path, string $source, Entry $entry): array
    {
        $size = $resource->storage->size($path);
        if ($size > $this->maxBytes) {
            throw new SoFinderException('This entry is larger than the entire recycle bin capacity.', 'trash_entry_too_large', 507);
        }
        $statistics = $this->statistics();
        $purgedItems = 0;
        $purgedBytes = 0;
        $oldestFirst = array_reverse($this->list());
        while (($statistics['usedItems'] >= $this->maxItems || $size > $this->maxBytes - $statistics['usedBytes']) && $oldestFirst !== []) {
            $oldest = array_shift($oldestFirst);
            if (!$oldest instanceof TrashItem) {
                continue;
            }
            $this->removeTree($this->itemDirectory($oldest->id));
            if (file_exists($this->itemDirectory($oldest->id)) || is_link($this->itemDirectory($oldest->id))) {
                throw new SoFinderException('The oldest recycle bin item could not be removed to free capacity.', 'trash_cleanup_failed', 507);
            }
            --$statistics['usedItems'];
            $statistics['usedBytes'] = max(0, $statistics['usedBytes'] - $oldest->size);
            ++$purgedItems;
            $purgedBytes += $oldest->size;
        }

        // The timestamp prefix provides deterministic FIFO ordering for multiple
        // deletions in the same second; the remaining 76 random bits keep IDs private.
        $id = sprintf('%013x', (int) floor(microtime(true) * 1000000)) . substr(bin2hex(random_bytes(10)), 0, 19);
        $directory = $this->actorRoot() . '/' . $id;
        if (!@mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new SoFinderException('Unable to prepare the private trash directory.', 'trash_unavailable', 500);
        }
        $now = time();
        $item = new TrashItem($id, $resource->resource->name, $path, $entry->directory, $size, $now, $now + ($this->retentionDays * 86400));
        $manifest = $directory . '/item.json';
        $temporaryManifest = $directory . '/item.tmp';
        if (@file_put_contents($temporaryManifest, json_encode($item, JSON_THROW_ON_ERROR), LOCK_EX) === false) {
            @rmdir($directory);
            throw new SoFinderException('Unable to record the trash manifest.', 'trash_unavailable', 500);
        }

        $payload = $directory . '/payload';
        if (!@rename($source, $payload)) {
            @unlink($temporaryManifest);
            @rmdir($directory);
            throw new SoFinderException('Unable to move the entry to private trash without risking data loss.', 'trash_move_failed', 500);
        }
        if (!@rename($temporaryManifest, $manifest)) {
            if (!@rename($payload, $source)) {
                throw new SoFinderException('Trash manifest creation failed and the entry could not be restored automatically.', 'trash_recovery_failed', 500);
            }
            @unlink($temporaryManifest);
            @rmdir($directory);
            throw new SoFinderException('Unable to publish the trash manifest.', 'trash_unavailable', 500);
        }

        return ['item' => $item, 'purgedItems' => $purgedItems, 'purgedBytes' => $purgedBytes];
    }

    /** @return list<TrashItem> */
    public function list(?string $resource = null): array
    {
        $root = $this->actorRoot(false);
        if (!is_dir($root)) {
            return [];
        }
        $items = [];
        foreach (new \FilesystemIterator($root, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO) as $directory) {
            if (!$directory instanceof \SplFileInfo) {
                continue;
            }
            if (!$directory->isDir() || $directory->isLink()) {
                continue;
            }
            try {
                $item = $this->readItem($directory->getFilename());
                if ($resource === null || $item->resource === $resource) {
                    $items[] = $item;
                }
            } catch (SoFinderException) {
            }
        }
        usort($items, static fn (TrashItem $a, TrashItem $b): int => ($b->deletedAt <=> $a->deletedAt) ?: strcmp($b->id, $a->id));

        return $items;
    }

    public function get(string $id): TrashItem
    {
        return $this->readItem($id);
    }

    public function restore(ResourceStorage $resource, string $id, string $conflict = 'cancel'): Entry
    {
        return $this->locked(fn (): Entry => $this->restoreLocked($resource, $id, $conflict));
    }

    private function restoreLocked(ResourceStorage $resource, string $id, string $conflict): Entry
    {
        if (!in_array($conflict, ['cancel', 'overwrite', 'rename'], true)) {
            throw new SoFinderException('The restore conflict strategy is invalid.', 'invalid_conflict_strategy', 400);
        }
        $item = $this->readItem($id);
        if ($item->resource !== $resource->resource->name) {
            throw new NotFoundException();
        }
        $directory = $this->itemDirectory($id);
        $payload = $directory . '/payload';
        if (!file_exists($payload) || is_link($payload)) {
            throw new SoFinderException('The trash payload is missing or unsafe.', 'trash_corrupt', 500);
        }

        $destinationPath = $item->path;
        if ($conflict === 'rename') {
            $destinationPath = $this->availableName($resource, $destinationPath);
        }
        $destinationPath = $this->paths->normalize($destinationPath);
        if ($item->directory) {
            $resource->resource->assertEntryPathAllowed($destinationPath, true);
        } else {
            $resource->resource->assertFileNameAllowed(basename($destinationPath));
            $resource->resource->assertEntryPathAllowed($destinationPath, false);
        }
        $destination = $this->destinationAbsolute($resource, $destinationPath);
        $exists = file_exists($destination) || is_link($destination);
        if ($exists && $conflict === 'cancel') {
            throw new ConflictException('An entry already exists at the original location.');
        }
        if (is_link($destination)) {
            throw new SoFinderException('Symbolic links cannot be overwritten.', 'invalid_path', 400);
        }

        $backup = null;
        if ($exists) {
            $backup = dirname($destination) . '/.sofinder-restore-backup-' . bin2hex(random_bytes(8));
            if (!@rename($destination, $backup)) {
                throw new SoFinderException('Unable to protect the existing restore destination.', 'atomic_replace_failed', 500);
            }
        }
        if (!@rename($payload, $destination)) {
            if ($backup !== null) {
                @rename($backup, $destination);
            }
            throw new SoFinderException('Unable to restore the trashed entry.', 'trash_restore_failed', 500);
        }
        if ($backup !== null) {
            $this->removeTree($backup);
        }
        @unlink($directory . '/item.json');
        @rmdir($directory);

        return $resource->storage->entry($destinationPath);
    }

    public function permanentlyDelete(string $id): void
    {
        $this->locked(function () use ($id): void {
            $this->readItem($id);
            $this->removeTree($this->itemDirectory($id));
        });
    }

    public function purgeExpired(?int $limit = null): int
    {
        if ($limit !== null && $limit < 1) {
            return 0;
        }
        if (!is_dir($this->root)) {
            return 0;
        }
        $purged = 0;
        foreach (new \FilesystemIterator($this->root, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO) as $actor) {
            if (!$actor instanceof \SplFileInfo) {
                continue;
            }
            if (!$actor->isDir() || $actor->isLink()) {
                continue;
            }
            foreach (new \FilesystemIterator($actor->getPathname(), \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO) as $directory) {
                if (!$directory instanceof \SplFileInfo) {
                    continue;
                }
                if (!$directory->isDir() || $directory->isLink()) {
                    continue;
                }
                $data = $this->readManifest($directory->getPathname() . '/item.json');
                if ($data !== null && (int) ($data['expiresAt'] ?? PHP_INT_MAX) <= time()) {
                    $this->removeTree($directory->getPathname());
                    ++$purged;
                    if ($limit !== null && $purged >= $limit) {
                        break 2;
                    }
                }
            }
        }

        return $purged;
    }

    private function readItem(string $id): TrashItem
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $id) !== 1) {
            throw new NotFoundException();
        }
        $data = $this->readManifest($this->itemDirectory($id) . '/item.json');
        if ($data === null || ($data['id'] ?? null) !== $id) {
            throw new NotFoundException();
        }

        return new TrashItem(
            $id,
            (string) ($data['resource'] ?? ''),
            $this->paths->normalize((string) ($data['path'] ?? '')),
            (bool) ($data['directory'] ?? false),
            (int) ($data['size'] ?? 0),
            (int) ($data['deletedAt'] ?? 0),
            (int) ($data['expiresAt'] ?? 0),
        );
    }

    /** @return array<string, mixed>|null */
    private function readManifest(string $file): ?array
    {
        $json = @file_get_contents($file);
        if ($json === false) {
            return null;
        }
        try {
            $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($data) ? $data : null;
    }

    private function actorRoot(bool $create = true): string
    {
        $directory = rtrim($this->root, '/') . '/' . hash('sha256', $this->actors->actorId());
        if ($create && !is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new SoFinderException('Unable to access the private trash directory.', 'trash_unavailable', 500);
        }

        return $directory;
    }

    /**
     * @template T
     * @param callable():T $operation
     * @return T
     */
    private function locked(callable $operation): mixed
    {
        $root = $this->actorRoot();
        $lock = @fopen($root . '/.trash.lock', 'c+b');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) fclose($lock);
            throw new SoFinderException('Unable to lock the private trash directory.', 'trash_unavailable', 500);
        }
        try {
            return $operation();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function itemDirectory(string $id): string
    {
        return $this->actorRoot(false) . '/' . $id;
    }

    private function destinationAbsolute(ResourceStorage $resource, string $path): string
    {
        $path = $this->paths->normalize($path);
        $root = realpath($resource->resource->root);
        $parent = realpath($resource->resource->root . '/' . dirname($path));
        if ($root === false || $parent === false || ($parent !== $root && !str_starts_with($parent, $root . DIRECTORY_SEPARATOR))) {
            throw new SoFinderException('The original parent folder no longer exists.', 'restore_parent_missing', 409);
        }

        return $parent . '/' . basename($path);
    }

    private function availableName(ResourceStorage $resource, string $path): string
    {
        try {
            $resource->storage->entry($path);
        } catch (NotFoundException) {
            return $path;
        }
        $directory = dirname($path);
        $directory = $directory === '.' ? '' : $directory;
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $name = basename($path);
        $stem = $extension === '' ? $name : substr($name, 0, -(strlen($extension) + 1));
        for ($index = 1; $index <= 999; ++$index) {
            $candidate = ($directory === '' ? '' : $directory . '/') . $stem . ' (' . $index . ')' . ($extension === '' ? '' : '.' . $extension);
            try {
                $resource->storage->entry($candidate);
            } catch (NotFoundException) {
                return $candidate;
            }
        }
        throw new ConflictException('Unable to find an available restore name.');
    }

    private function assertTreeHasNoLinks(string $path): void
    {
        if (is_link($path)) {
            throw new SoFinderException('Symbolic links cannot be moved to trash.', 'invalid_path', 400);
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO) as $entry) {
            if (!$entry instanceof \SplFileInfo) {
                continue;
            }
            $this->assertTreeHasNoLinks($entry->getPathname());
        }
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO) as $entry) {
            if (!$entry instanceof \SplFileInfo) {
                continue;
            }
            $this->removeTree($entry->getPathname());
        }
        @rmdir($path);
    }
}

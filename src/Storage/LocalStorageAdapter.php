<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Storage;

use SohoPHP\SoFinder\Contract\StorageAdapterInterface;
use SohoPHP\SoFinder\Contract\LocalPathProviderInterface;
use SohoPHP\SoFinder\Contract\StorageUsageProviderInterface;
use SohoPHP\SoFinder\Contract\StorageHealthProbeInterface;
use SohoPHP\SoFinder\Exception\ConflictException;
use SohoPHP\SoFinder\Exception\InvalidPathException;
use SohoPHP\SoFinder\Exception\NotFoundException;
use SohoPHP\SoFinder\Exception\SoFinderException;
use SohoPHP\SoFinder\Security\PathGuard;
use SohoPHP\SoFinder\Value\Entry;
use SohoPHP\SoFinder\Value\ListQuery;
use SohoPHP\SoFinder\Value\ListingPage;
use SohoPHP\SoFinder\Value\StorageCapabilities;

final class LocalStorageAdapter implements StorageAdapterInterface, LocalPathProviderInterface, StorageUsageProviderInterface, StorageHealthProbeInterface
{
    private readonly string $root;

    public function __construct(
        string $root,
        private readonly string $baseUrl = '',
        private readonly PathGuard $pathGuard = new PathGuard(),
        private readonly int $directoryMode = 0775,
        private readonly int $fileMode = 0664,
    ) {
        $created = !is_dir($root);
        if (!is_dir($root) && !@mkdir($root, $this->directoryMode, true) && !is_dir($root)) {
            throw new \RuntimeException(sprintf('Unable to create SoFinder storage root "%s".', $root));
        }
        if ($created) {
            @chmod($root, $this->directoryMode);
        }
        $resolved = realpath($root);
        if ($resolved === false) {
            throw new \RuntimeException(sprintf('Unable to resolve SoFinder storage root "%s".', $root));
        }
        $this->root = rtrim($resolved, DIRECTORY_SEPARATOR);
    }

    public function list(ListQuery $query): ListingPage
    {
        $relative = $this->pathGuard->normalize($query->path);
        $absolute = $this->resolveExisting($relative);
        if (!is_dir($absolute)) {
            throw new InvalidPathException('The requested path is not a directory.');
        }
        $directoryIdentity = $this->filesystemIdentity($absolute);

        $entries = [];
        try {
            $iterator = new \FilesystemIterator($absolute, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO);
            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo) {
                    continue;
                }
                if ($file->isLink() || str_starts_with($file->getFilename(), '.')) {
                    continue;
                }
                $child = $relative === '' ? $file->getFilename() : $relative . '/' . $file->getFilename();
                try {
                    $entries[] = $this->makeEntry($child, $file->getPathname());
                } catch (NotFoundException) {
                    // A concurrent deletion is a normal directory-listing race.
                    continue;
                } catch (SoFinderException $exception) {
                    if ($exception->errorCode !== 'storage_changed') {
                        throw $exception;
                    }
                    // Never expose a child that changed identity while it was inspected.
                    continue;
                }
            }
        } catch (\UnexpectedValueException $exception) {
            clearstatcache(true, $absolute);
            if (!is_dir($absolute)) {
                throw new NotFoundException();
            }
            throw new SoFinderException('The requested directory cannot be read.', 'storage_unavailable', 503, $exception);
        }
        clearstatcache(true, $absolute);
        if (!is_dir($absolute)) {
            throw new NotFoundException();
        }
        if ($directoryIdentity !== $this->filesystemIdentity($absolute)) {
            throw new SoFinderException('The requested directory changed while it was being listed.', 'storage_changed', 409);
        }
        if ($query->onlyPaths !== null) {
            $allowed = array_fill_keys($query->onlyPaths, true);
            $entries = array_values(array_filter($entries, static fn (Entry $entry): bool => isset($allowed[$entry->path])));
        }
        $search = trim($query->search);
        if ($search !== '') {
            $entries = array_values(array_filter($entries, static fn (Entry $entry): bool => mb_stripos($entry->name, $search) !== false));
        }
        if ($query->filter !== null) {
            $entries = array_values(array_filter($entries, $query->filter));
        }
        usort($entries, static function (Entry $a, Entry $b) use ($query): int {
            if ($a->directory !== $b->directory) {
                return $a->directory ? -1 : 1;
            }
            $comparison = match ($query->sort) {
                'size' => $a->size <=> $b->size,
                'type' => strnatcasecmp($a->mimeType ?? '', $b->mimeType ?? ''),
                'modified' => $a->modifiedAt <=> $b->modifiedAt,
                default => strnatcasecmp($a->name, $b->name),
            };
            if ($comparison === 0) {
                $comparison = strnatcasecmp($a->name, $b->name);
            }

            return $query->direction === 'desc' ? -$comparison : $comparison;
        });

        $total = count($entries);
        $requestedOffset = $query->offset;
        if ($query->cursor !== null) {
            $decoded = base64_decode($query->cursor, true);
            if ($decoded === false || preg_match('/^\d+$/D', $decoded) !== 1) {
                throw new InvalidPathException('The pagination cursor is invalid.');
            }
            $requestedOffset = (int) $decoded;
        }
        $maximumOffset = $total === 0 ? 0 : intdiv($total - 1, $query->limit) * $query->limit;
        $offset = min($requestedOffset, $maximumOffset);

        return new ListingPage(
            array_slice($entries, $offset, $query->limit),
            $total,
            $offset,
            $query->limit,
            $offset + $query->limit < $total ? base64_encode((string) ($offset + $query->limit)) : null,
        );
    }

    public function capabilities(): StorageCapabilities
    {
        return new StorageCapabilities(
            atomicMove: true,
            nativeCopy: true,
            recoverableDelete: true,
            publicUrl: $this->baseUrl !== '',
        );
    }

    public function entry(string $path): Entry
    {
        $relative = $this->pathGuard->normalize($path);
        if ($relative === '') {
            clearstatcache(true, $this->root);
            return new Entry('', '', true, 0, (int) filemtime($this->root), null, $this->publicUrl(''));
        }

        return $this->makeEntry($relative, $this->resolveExisting($relative));
    }

    public function createDirectory(string $path): Entry
    {
        $relative = $this->pathGuard->normalize($path);
        if ($relative === '') {
            throw new InvalidPathException('A folder name is required.');
        }
        $absolute = $this->resolveDestination($relative);
        if (file_exists($absolute) || is_link($absolute)) {
            throw new ConflictException();
        }
        if (!@mkdir($absolute, $this->directoryMode) && !is_dir($absolute)) {
            throw new SoFinderException('Unable to create the folder.');
        }
        @chmod($absolute, $this->directoryMode);

        return $this->makeEntry($relative, $absolute);
    }

    public function writeStream(string $path, mixed $stream, bool $overwrite = false): Entry
    {
        if (!is_resource($stream)) {
            throw new \InvalidArgumentException('writeStream expects a stream resource.');
        }
        $relative = $this->pathGuard->normalize($path);
        $absolute = $this->resolveDestination($relative);
        if (!$overwrite && (file_exists($absolute) || is_link($absolute))) {
            throw new ConflictException();
        }

        $temporary = tempnam(dirname($absolute), '.sofinder-upload-');
        if ($temporary === false) {
            throw new SoFinderException('Unable to create a temporary upload file.');
        }
        try {
            $output = @fopen($temporary, 'wb');
            if ($output === false || stream_copy_to_stream($stream, $output) === false) {
                throw new SoFinderException('Unable to write the uploaded file.');
            }
            fclose($output);
            $this->promoteStaged($temporary, $absolute, $overwrite);
            @chmod($absolute, $this->fileMode);
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }

        return $this->makeEntry($relative, $absolute);
    }

    public function readStream(string $path): mixed
    {
        $absolute = $this->resolveExisting($this->pathGuard->normalize($path));
        if (!is_file($absolute)) {
            throw new InvalidPathException('The requested entry is not a file.');
        }
        $stream = @fopen($absolute, 'rb');
        if ($stream === false) {
            throw new SoFinderException('Unable to read the file.');
        }

        return $stream;
    }

    public function move(string $source, string $destination, bool $overwrite = false): Entry
    {
        $source = $this->pathGuard->normalize($source);
        $destination = $this->pathGuard->normalize($destination);
        $sourcePath = $this->resolveExisting($source);
        $this->assertValidTransfer($source, $destination, $sourcePath);
        $destinationPath = $this->resolveDestination($destination);
        $this->assertDestinationAvailable($destinationPath, $overwrite);
        $this->promoteStaged($sourcePath, $destinationPath, $overwrite);

        return $this->makeEntry($destination, $destinationPath);
    }

    public function copy(string $source, string $destination, bool $overwrite = false): Entry
    {
        $source = $this->pathGuard->normalize($source);
        $destination = $this->pathGuard->normalize($destination);
        $sourcePath = $this->resolveExisting($source);
        $this->assertValidTransfer($source, $destination, $sourcePath);
        $destinationPath = $this->resolveDestination($destination);
        $this->assertDestinationAvailable($destinationPath, $overwrite);
        $staged = $this->vacantSiblingPath($destinationPath, '.sofinder-copy-');
        try {
            $this->copyAbsolute($sourcePath, $staged);
            $this->promoteStaged($staged, $destinationPath, $overwrite);
        } finally {
            if (file_exists($staged) || is_link($staged)) {
                $this->removeAbsolute($staged);
            }
        }

        return $this->makeEntry($destination, $destinationPath);
    }

    public function delete(string $path): void
    {
        $relative = $this->pathGuard->normalize($path);
        if ($relative === '') {
            throw new InvalidPathException('The storage root cannot be deleted.');
        }
        $this->removeAbsolute($this->resolveExisting($relative));
    }

    public function publicUrl(string $path): ?string
    {
        if ($this->baseUrl === '') {
            return null;
        }
        $relative = $this->pathGuard->normalize($path);
        $encoded = implode('/', array_map('rawurlencode', $relative === '' ? [] : explode('/', $relative)));

        return rtrim($this->baseUrl, '/') . ($encoded === '' ? '/' : '/' . $encoded);
    }

    public function absolutePath(string $path): string
    {
        return $this->resolveExisting($this->pathGuard->normalize($path));
    }

    public function usage(): int
    {
        return $this->sizeAbsolute($this->root);
    }

    public function checkStorage(): void
    {
        if (!is_dir($this->root) || !is_readable($this->root)) throw new \RuntimeException('The local storage root is unavailable.');
    }

    public function size(string $path): int
    {
        return $this->sizeAbsolute($this->resolveExisting($this->pathGuard->normalize($path)));
    }

    private function resolveExisting(string $relative): string
    {
        $candidate = $relative === '' ? $this->root : $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $this->assertNoSymlink($relative);
        $resolved = realpath($candidate);
        if ($resolved === false || !$this->isInsideRoot($resolved)) {
            throw new NotFoundException();
        }

        return $resolved;
    }

    private function resolveDestination(string $relative): string
    {
        if ($relative === '') {
            throw new InvalidPathException();
        }
        $parent = dirname($relative);
        $parent = $parent === '.' ? '' : str_replace('\\', '/', $parent);
        $parentPath = $this->resolveExisting($parent);
        if (!is_dir($parentPath)) {
            throw new InvalidPathException('The destination folder does not exist.');
        }

        return $parentPath . DIRECTORY_SEPARATOR . basename($relative);
    }

    private function assertNoSymlink(string $relative): void
    {
        $current = $this->root;
        foreach ($relative === '' ? [] : explode('/', $relative) as $segment) {
            $current .= DIRECTORY_SEPARATOR . $segment;
            if (is_link($current)) {
                throw new InvalidPathException('Symbolic links are not accessible.');
            }
        }
    }

    private function isInsideRoot(string $path): bool
    {
        return $path === $this->root || str_starts_with($path, $this->root . DIRECTORY_SEPARATOR);
    }

    private function makeEntry(string $relative, string $absolute): Entry
    {
        // Long-lived workers and repeated operations in the same request must
        // observe replacements made after an earlier stat of this path.
        clearstatcache(true, $absolute);
        if (is_link($absolute)) {
            throw new InvalidPathException('Symbolic links are not accessible.');
        }
        $identity = $this->filesystemIdentity($absolute);
        $directory = is_dir($absolute);
        $size = $directory ? 0 : @filesize($absolute);
        $modifiedAt = @filemtime($absolute);
        $mime = $directory ? null : (@(new \finfo(FILEINFO_MIME_TYPE))->file($absolute) ?: 'application/octet-stream');
        clearstatcache(true, $absolute);
        if (is_link($absolute)) {
            throw new InvalidPathException('Symbolic links are not accessible.');
        }
        if ($size === false || $modifiedAt === false || $identity !== $this->filesystemIdentity($absolute)) {
            throw new SoFinderException('The entry changed while it was being inspected.', 'storage_changed', 409);
        }

        return new Entry(
            $relative,
            basename($absolute),
            $directory,
            (int) $size,
            (int) $modifiedAt,
            $mime,
            $directory ? null : $this->publicUrl($relative),
        );
    }

    /** @return array{dev: int, ino: int, mode: int} */
    private function filesystemIdentity(string $absolute): array
    {
        $stat = @lstat($absolute);
        if ($stat === false) {
            throw new NotFoundException();
        }

        return ['dev' => (int) $stat['dev'], 'ino' => (int) $stat['ino'], 'mode' => (int) $stat['mode']];
    }

    private function assertDestinationAvailable(string $destination, bool $overwrite): void
    {
        if (is_link($destination)) {
            throw new InvalidPathException('Symbolic links are not accessible.');
        }
        if (file_exists($destination) && !$overwrite) {
            throw new ConflictException();
        }
    }

    private function promoteStaged(string $staged, string $destination, bool $overwrite): void
    {
        $exists = file_exists($destination) || is_link($destination);
        if ($exists && !$overwrite) {
            throw new ConflictException();
        }
        if (is_link($destination)) {
            throw new InvalidPathException('Symbolic links are not accessible.');
        }
        $backup = null;
        if ($exists) {
            $backup = $this->vacantSiblingPath($destination, '.sofinder-backup-');
            if (!@rename($destination, $backup)) {
                throw new SoFinderException('Unable to protect the existing destination before replacement.', 'atomic_replace_failed', 500);
            }
        }
        if (@rename($staged, $destination)) {
            if ($backup !== null) {
                try {
                    $this->removeAbsolute($backup);
                } catch (\Throwable) {
                    // A hidden backup is safer than failing an operation that already completed.
                }
            }
            return;
        }
        if ($backup !== null && !@rename($backup, $destination)) {
            throw new SoFinderException('Unable to publish the replacement or restore the previous destination.', 'atomic_restore_failed', 500);
        }

        throw new SoFinderException('Unable to publish the prepared file operation.', 'atomic_replace_failed', 500);
    }

    private function vacantSiblingPath(string $destination, string $prefix): string
    {
        $temporary = tempnam(dirname($destination), $prefix);
        if ($temporary === false || !@unlink($temporary)) {
            throw new SoFinderException('Unable to prepare an atomic file operation.', 'atomic_replace_failed', 500);
        }

        return $temporary;
    }

    private function assertValidTransfer(string $source, string $destination, string $sourcePath): void
    {
        if ($source === $destination || (is_dir($sourcePath) && str_starts_with($destination, $source . '/'))) {
            throw new InvalidPathException('An entry cannot be copied or moved into itself.');
        }
    }

    private function removeAbsolute(string $path): void
    {
        if (is_link($path)) {
            throw new InvalidPathException('Symbolic links are not accessible.');
        }
        if (is_dir($path)) {
            foreach (new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO) as $child) {
                if (!$child instanceof \SplFileInfo) {
                    continue;
                }
                $this->removeAbsolute($child->getPathname());
            }
            if (!@rmdir($path)) {
                throw new SoFinderException('Unable to delete the folder.');
            }
            return;
        }
        if (!@unlink($path)) {
            throw new SoFinderException('Unable to delete the file.');
        }
    }

    private function copyAbsolute(string $source, string $destination): void
    {
        if (is_link($source)) {
            throw new InvalidPathException('Symbolic links are not accessible.');
        }
        if (is_dir($source)) {
            if (!@mkdir($destination, $this->directoryMode) && !is_dir($destination)) {
                throw new SoFinderException('Unable to copy the folder.');
            }
            @chmod($destination, $this->directoryMode);
            foreach (new \FilesystemIterator($source, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO) as $child) {
                if (!$child instanceof \SplFileInfo) {
                    continue;
                }
                $this->copyAbsolute($child->getPathname(), $destination . DIRECTORY_SEPARATOR . $child->getFilename());
            }
            return;
        }
        if (!@copy($source, $destination)) {
            throw new SoFinderException('Unable to copy the file.');
        }
        @chmod($destination, $this->fileMode);
    }

    private function sizeAbsolute(string $path): int
    {
        if (is_link($path)) {
            throw new InvalidPathException('Symbolic links are not accessible.');
        }
        if (!is_dir($path)) {
            return (int) filesize($path);
        }
        $bytes = 0;
        foreach (new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO) as $child) {
            if (!$child instanceof \SplFileInfo) {
                continue;
            }
            $bytes += $this->sizeAbsolute($child->getPathname());
        }

        return $bytes;
    }
}

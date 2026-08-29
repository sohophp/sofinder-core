<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Archive;

use SohoPHP\SoFinder\Exception\SoFinderException;
use SohoPHP\SoFinder\FileManager;
use SohoPHP\SoFinder\Security\PathGuard;

final class ArchiveManager
{
    private int $entries = 0;
    private int $bytes = 0;
    private int $maximumEntries = 1_000;
    private int $maximumBytes = 536_870_912;
    /** @var list<string> */
    private array $temporaryFiles = [];

    public function __construct(
        private readonly FileManager $files,
        private readonly PathGuard $pathGuard,
        private readonly string $cacheDirectory,
    ) {
    }

    /** @param list<string> $paths */
    public function create(string $resource, array $paths): string
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new SoFinderException('ZIP support is not available.', 'archive_unavailable', 501);
        }
        $paths = array_values(array_unique(array_map($this->pathGuard->normalize(...), $paths)));
        if ($paths === [] || in_array('', $paths, true)) {
            throw new SoFinderException('Select at least one non-root entry for download.', 'invalid_archive_selection', 422);
        }
        $limits = $this->files->archiveLimits($resource, $paths[0]);
        if (count($paths) > $limits['maxSelection']) {
            throw new SoFinderException(sprintf('Select no more than %d entries for download.', $limits['maxSelection']), 'invalid_archive_selection', 422);
        }
        $this->maximumEntries = min($limits['maxItems'], $limits['maxRecursiveItems']);
        $this->maximumBytes = $limits['maxBytes'];
        $directory = rtrim($this->cacheDirectory, DIRECTORY_SEPARATOR) . '/archives';
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new SoFinderException('Unable to create the archive workspace.', 'archive_failed', 500);
        }
        $archivePath = tempnam($directory, 'sofinder-archive-');
        if ($archivePath === false) {
            throw new SoFinderException('Unable to create the archive.', 'archive_failed', 500);
        }
        $archive = new \ZipArchive();
        if ($archive->open($archivePath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            @unlink($archivePath);
            throw new SoFinderException('Unable to open the archive.', 'archive_failed', 500);
        }
        $this->entries = 0;
        $this->bytes = 0;
        $this->temporaryFiles = [];
        try {
            foreach ($paths as $path) {
                $entry = $this->files->entry($resource, $path);
                $this->append($archive, $resource, $entry->path, $entry->name);
            }
            if (!$archive->close()) {
                throw new SoFinderException('Unable to finalize the archive.', 'archive_failed', 500);
            }
        } catch (\Throwable $exception) {
            $archive->close();
            @unlink($archivePath);
            throw $exception;
        } finally {
            foreach ($this->temporaryFiles as $temporary) {
                @unlink($temporary);
            }
            $this->temporaryFiles = [];
        }

        return $archivePath;
    }

    private function append(\ZipArchive $archive, string $resource, string $path, string $archiveName): void
    {
        $entry = $this->files->entry($resource, $path);
        $this->guardLimits($entry->directory ? 0 : $entry->size);
        if ($entry->directory) {
            $archive->addEmptyDir($archiveName);
            $offset = 0;
            do {
                $listing = $this->files->list($resource, $path, '', 'name', 'asc', $offset, 500);
                foreach ($listing['entries'] as $child) {
                    $this->append($archive, $resource, $child->path, $archiveName . '/' . $child->name);
                }
                $offset += $listing['limit'];
            } while ($offset < $listing['total']);

            return;
        }
        $temporary = tempnam(dirname($archive->filename), 'sofinder-archive-entry-');
        if ($temporary === false) {
            throw new SoFinderException('Unable to prepare an archive entry.', 'archive_failed', 500);
        }
        $this->temporaryFiles[] = $temporary;
        $input = $this->files->read($resource, $path);
        $output = fopen($temporary, 'wb');
        try {
            if ($output === false || stream_copy_to_stream($input, $output) === false) {
                throw new SoFinderException('Unable to prepare an archive entry.', 'archive_failed', 500);
            }
        } finally {
            fclose($input);
            if (is_resource($output)) {
                fclose($output);
            }
        }
        if (!$archive->addFile($temporary, $archiveName)) {
            throw new SoFinderException('Unable to add a file to the archive.', 'archive_failed', 500);
        }
    }

    private function guardLimits(int $bytes): void
    {
        ++$this->entries;
        $this->bytes += $bytes;
        if ($this->entries > $this->maximumEntries || $this->bytes > $this->maximumBytes) {
            throw new SoFinderException(sprintf('The archive exceeds the configured %d entry or %d byte limit.', $this->maximumEntries, $this->maximumBytes), 'archive_limit_exceeded', 413);
        }
    }
}

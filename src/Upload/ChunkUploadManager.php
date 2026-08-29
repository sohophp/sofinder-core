<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Upload;

use SohoPHP\SoFinder\Contract\ActorProviderInterface;
use SohoPHP\SoFinder\Contract\ChunkUploadStoreInterface;
use SohoPHP\SoFinder\Exception\SoFinderException;

final readonly class ChunkUploadManager implements ChunkUploadStoreInterface
{
    public function __construct(
        private string $root,
        private ActorProviderInterface $actors,
        private int $maximumChunkBytes = 5_242_880,
        private int $maximumChunks = 200,
    ) {
    }

    /**
     * @param resource $stream
     * @return array{complete:bool,path?:string,size?:int}
     */
    public function accept(string $id, int $index, int $total, mixed $stream, int $maximumFileBytes, array $context = []): array
    {
        $directory = $this->directory($id, true);
        if ($index < 0 || $total < 1 || $total > $this->maximumChunks || $index >= $total || !is_resource($stream)) {
            throw new SoFinderException('The chunk upload coordinates are invalid.', 'invalid_upload_chunk', 400);
        }
        $lock = @fopen($directory . '/session.lock', 'c+b');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) fclose($lock);
            throw new SoFinderException('The chunk upload session is busy.', 'chunk_session_unavailable', 503);
        }
        try {
            $this->persistManifest($directory, $id, $total, $context);
            $part = $directory . '/part-' . $index;
            $temporary = $part . '.tmp-' . bin2hex(random_bytes(6));
            $output = @fopen($temporary, 'wb');
            if ($output === false) {
                throw new SoFinderException('Unable to stage the upload chunk.', 'chunk_write_failed', 500);
            }
            $written = 0;
            try {
                while (!feof($stream)) {
                    $chunk = fread($stream, 65_536);
                    if ($chunk === false) throw new SoFinderException('Unable to read the upload chunk.', 'invalid_upload_chunk', 400);
                    if ($chunk === '') break;
                    $written += strlen($chunk);
                    if ($written > $this->maximumChunkBytes || $written > $maximumFileBytes) {
                        throw new SoFinderException('The upload chunk exceeds the configured limit.', 'upload_chunk_too_large', 413);
                    }
                    if (fwrite($output, $chunk) !== strlen($chunk)) throw new SoFinderException('Unable to save the upload chunk.', 'chunk_write_failed', 500);
                }
            } finally {
                fclose($output);
            }
            if (!@rename($temporary, $part)) {
                @unlink($temporary);
                throw new SoFinderException('Unable to publish the upload chunk.', 'chunk_write_failed', 500);
            }
            @touch($directory);

            $size = 0;
            for ($partIndex = 0; $partIndex < $total; ++$partIndex) {
                $candidate = $directory . '/part-' . $partIndex;
                if (!is_file($candidate)) return ['complete' => false];
                $size += (int) filesize($candidate);
                if ($size > $maximumFileBytes) {
                    throw new SoFinderException('The assembled upload exceeds the configured file size.', 'file_too_large', 413);
                }
            }
            $assembled = $directory . '/assembled';
            $combined = @fopen($assembled . '.tmp', 'wb');
            if ($combined === false) throw new SoFinderException('Unable to assemble the upload.', 'chunk_assembly_failed', 500);
            try {
                for ($partIndex = 0; $partIndex < $total; ++$partIndex) {
                    $input = @fopen($directory . '/part-' . $partIndex, 'rb');
                    if ($input === false) throw new SoFinderException('An upload chunk disappeared during assembly.', 'chunk_assembly_failed', 500);
                    try {
                        if (stream_copy_to_stream($input, $combined) === false) throw new SoFinderException('Unable to assemble the upload.', 'chunk_assembly_failed', 500);
                    } finally { fclose($input); }
                }
            } finally { fclose($combined); }
            if (!@rename($assembled . '.tmp', $assembled)) throw new SoFinderException('Unable to publish the assembled upload.', 'chunk_assembly_failed', 500);

            return ['complete' => true, 'path' => $assembled, 'size' => $size];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function discard(string $id): void
    {
        $directory = $this->directory($id, false);
        if (!is_dir($directory)) return;
        foreach (new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO) as $file) {
            if (!$file instanceof \SplFileInfo) continue;
            if ($file->isFile() && !$file->isLink()) @unlink($file->getPathname());
        }
        @rmdir($directory);
    }

    public function status(string $id): array
    {
        $directory = $this->directory($id, false);
        $manifest = $this->readManifest($directory);
        $received = [];
        for ($index = 0; $index < $manifest['total']; ++$index) {
            if (is_file($directory . '/part-' . $index)) {
                $received[] = $index;
            }
        }

        return $manifest + [
            'received' => $received,
            'complete' => is_file($directory . '/assembled'),
            'updatedAt' => (int) filemtime($directory),
        ];
    }

    public function cleanupExpired(bool $allActors = false, ?int $limit = null): int
    {
        if ($limit !== null && $limit < 1) return 0;
        $root = $allActors ? rtrim($this->root, '/') : $this->actorRoot(false);
        if (!is_dir($root)) return 0;
        $purged = 0;
        $actorPaths = $allActors ? [] : [$root];
        if ($allActors) {
            foreach (new \FilesystemIterator($root, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO) as $actor) {
                if ($actor instanceof \SplFileInfo) $actorPaths[] = $actor->getPathname();
            }
        }
        foreach ($actorPaths as $actorPath) {
            if (!is_dir($actorPath) || is_link($actorPath)) continue;
            foreach (new \FilesystemIterator($actorPath, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO) as $directory) {
                if (!$directory instanceof \SplFileInfo) continue;
                if (!$directory->isDir() || $directory->isLink() || $directory->getMTime() >= time() - 86_400) continue;
                $this->removeDirectory($directory->getPathname());
                if (!is_dir($directory->getPathname())) {
                    ++$purged;
                    if ($limit !== null && $purged >= $limit) return $purged;
                }
            }
        }

        return $purged;
    }

    /** @param array<string, mixed> $context */
    private function persistManifest(string $directory, string $id, int $total, array $context): void
    {
        $normalized = [
            'id' => $id,
            'total' => $total,
            'resource' => (string) ($context['resource'] ?? ''),
            'path' => (string) ($context['path'] ?? ''),
            'name' => (string) ($context['name'] ?? ''),
            'overwrite' => (bool) ($context['overwrite'] ?? false),
            'autoRename' => (bool) ($context['autoRename'] ?? false),
            'workspace' => (string) ($context['workspace'] ?? ''),
        ];
        $file = $directory . '/session.json';
        if (is_file($file)) {
            $stored = $this->readManifest($directory);
            if ($stored !== $normalized) {
                throw new SoFinderException('The upload session metadata does not match the existing session.', 'upload_session_mismatch', 409);
            }
            return;
        }
        $temporary = $file . '.tmp-' . bin2hex(random_bytes(6));
        if (@file_put_contents($temporary, json_encode($normalized, JSON_THROW_ON_ERROR), LOCK_EX) === false || !@rename($temporary, $file)) {
            @unlink($temporary);
            throw new SoFinderException('Unable to persist the upload session.', 'chunk_session_unavailable', 503);
        }
        @chmod($file, 0660);
    }

    /** @return array{id:string,total:int,resource:string,path:string,name:string,overwrite:bool,autoRename:bool,workspace:string} */
    private function readManifest(string $directory): array
    {
        $contents = @file_get_contents($directory . '/session.json');
        $data = $contents === false ? null : json_decode($contents, true);
        if (!is_array($data) || !isset($data['id'], $data['total'], $data['resource'], $data['path'], $data['name'], $data['overwrite'])) {
            throw new SoFinderException('The upload session does not exist or is invalid.', 'upload_session_not_found', 404);
        }

        return [
            'id' => (string) $data['id'],
            'total' => (int) $data['total'],
            'resource' => (string) $data['resource'],
            'path' => (string) $data['path'],
            'name' => (string) $data['name'],
            'overwrite' => (bool) $data['overwrite'],
            'autoRename' => (bool) ($data['autoRename'] ?? false),
            'workspace' => (string) ($data['workspace'] ?? ''),
        ];
    }

    private function removeDirectory(string $directory): void
    {
        foreach (new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO) as $file) {
            if (!$file instanceof \SplFileInfo) continue;
            if ($file->isFile() && !$file->isLink()) @unlink($file->getPathname());
        }
        @rmdir($directory);
    }

    private function directory(string $id, bool $create): string
    {
        if (preg_match('/^[a-zA-Z0-9_-]{16,80}$/D', $id) !== 1) {
            throw new SoFinderException('The chunk upload identifier is invalid.', 'invalid_upload_chunk', 400);
        }
        $directory = $this->actorRoot($create) . '/' . $id;
        if ($create && !is_dir($directory) && !@mkdir($directory, 0770) && !is_dir($directory)) {
            throw new SoFinderException('Unable to create the chunk upload session.', 'chunk_session_unavailable', 503);
        }

        return $directory;
    }

    private function actorRoot(bool $create): string
    {
        $directory = rtrim($this->root, '/') . '/' . hash('sha256', $this->actors->actorId());
        if ($create && !is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new SoFinderException('Unable to access the private chunk upload directory.', 'chunk_session_unavailable', 503);
        }

        return $directory;
    }
}

<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Maintenance;

use SohoPHP\SoFinder\Preview\DocumentPreviewJobManager;

final readonly class CacheCleaner
{
    public function __construct(private string $cacheDirectory, private ?DocumentPreviewJobManager $documentPreviews = null)
    {
    }

    /** @return array{dryRun:bool,olderThanSeconds:int,matched:int,removed:int,bytes:int,errors:list<string>} */
    public function clean(int $olderThanSeconds, bool $dryRun): array
    {
        if ($olderThanSeconds < 60) throw new \InvalidArgumentException('Cache cleanup age must be at least 60 seconds.');
        $cutoff = time() - $olderThanSeconds;
        $matched = $removed = $bytes = 0;
        $errors = [];
        foreach (['thumbnails' => ['png'], 'variants' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp'], 'document-previews' => ['pdf']] as $directory => $extensions) {
            $path = rtrim($this->cacheDirectory, '/') . '/' . $directory;
            if (!is_dir($path)) continue;
            foreach (new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO) as $file) {
                if (!$file instanceof \SplFileInfo || !$file->isFile() || $file->isLink()) continue;
                if (!in_array(strtolower($file->getExtension()), $extensions, true) || $file->getMTime() > $cutoff) continue;
                ++$matched;
                $size = max(0, $file->getSize());
                $bytes += $size;
                if (!$dryRun) {
                    if (@unlink($file->getPathname())) ++$removed;
                    else $errors[] = $directory . '/' . $file->getFilename();
                }
            }
        }
        if (!$dryRun && $this->documentPreviews !== null) $removed += $this->documentPreviews->cleanup();

        return compact('dryRun', 'olderThanSeconds', 'matched', 'removed', 'bytes', 'errors');
    }
}

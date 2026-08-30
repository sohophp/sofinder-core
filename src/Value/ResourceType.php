<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Value;

use SohoPHP\SoFinder\Exception\SoFinderException;

final class ResourceType implements \JsonSerializable
{
    /**
     * @param list<string> $allowedExtensions
     * @param list<string> $deniedExtensions
     * @param list<string> $allowedMimeTypes
     * @param list<string> $requiredRoles
     * @param array<string, list<string>> $operationRoles
     * @param list<array{path:string,operations:list<string>,roles:list<string>,allow:bool}> $pathAcl
     */
    public function __construct(
        public readonly string $name,
        public readonly string $root,
        public readonly string $publicUrl,
        public readonly array $allowedExtensions = [],
        public readonly array $deniedExtensions = [],
        public readonly array $allowedMimeTypes = [],
        public readonly int $maxSize = 20_971_520,
        public readonly bool $readOnly = false,
        public readonly int $quotaBytes = 0,
        public readonly array $requiredRoles = [],
        public readonly array $operationRoles = [],
        public readonly int $maxFileNameLength = 120,
        public readonly int $maxFolderNameLength = 50,
        public readonly int $maxFolderDepth = 5,
        public readonly int $maxImagePixels = 50_000_000,
        public readonly array $pathAcl = [],
        public readonly string $deliveryMode = 'public',
        public readonly int $maxBatchItems = 100,
        public readonly int $maxRecursiveItems = 10_000,
        public readonly int $maxArchiveItems = 1_000,
        public readonly int $maxArchiveBytes = 536_870_912,
        public readonly int $maxImageWidth = 12_000,
        public readonly int $maxImageHeight = 12_000,
        public readonly string $animatedImagePolicy = 'preserve',
        public readonly string $entryUrlRoute = '',
        /** @var array<string, string> */
        public readonly array $entryUrlParameters = [],
        public readonly bool $entryUrlAbsolute = false,
    ) {
        if ($name === '' || $root === '') {
            throw new \InvalidArgumentException('Resource name and root are required.');
        }
        if ($maxFileNameLength < 1 || $maxFileNameLength > 255 || $maxFolderNameLength < 1 || $maxFolderNameLength > 255 || $maxFolderDepth < 1 || $maxFolderDepth > 100 || $maxImagePixels < 1) {
            throw new \InvalidArgumentException('Resource name and folder limits are outside the supported range.');
        }
        if (!in_array($deliveryMode, ['public', 'proxy'], true)) {
            throw new \InvalidArgumentException('Resource delivery mode must be public or proxy.');
        }
        if ($maxBatchItems < 1 || $maxRecursiveItems < 1 || $maxArchiveItems < 1 || $maxArchiveBytes < 1) {
            throw new \InvalidArgumentException('Resource operation limits must be positive.');
        }
        if ($maxImageWidth < 1 || $maxImageHeight < 1 || !in_array($animatedImagePolicy, ['preserve', 'reject'], true)) {
            throw new \InvalidArgumentException('Resource image dimension or animation policy is invalid.');
        }
    }

    public function assertMimeAllowed(string $mimeType): void
    {
        if ($this->allowedMimeTypes !== [] && !in_array(strtolower($mimeType), array_map('strtolower', $this->allowedMimeTypes), true)) {
            throw new SoFinderException('The uploaded file content does not match an allowed media type.', 'invalid_mime_type', 415);
        }
    }

    public function assertUploadAllowed(string $fileName, int $size): void
    {
        if ($this->readOnly) {
            throw new SoFinderException('This resource is read-only.', 'read_only', 403);
        }
        if ($size < 0 || $size > $this->maxSize) {
            throw new SoFinderException('The uploaded file exceeds the configured size limit.', 'file_too_large', 413);
        }

        $this->assertFileNameAllowed($fileName);
    }

    public function assertFileNameAllowed(string $fileName): void
    {
        $this->assertEntryPathAllowed($fileName, false);
        $extension = strtolower((string) pathinfo($fileName, PATHINFO_EXTENSION));
        $segments = array_map('strtolower', explode('.', $fileName));
        $denied = array_map('strtolower', $this->deniedExtensions);
        if ($extension === '' || array_intersect($segments, $denied) !== []) {
            throw new SoFinderException('This file extension is not allowed.', 'invalid_extension', 415);
        }

        $allowed = array_map('strtolower', $this->allowedExtensions);
        if ($allowed !== [] && !in_array($extension, $allowed, true)) {
            throw new SoFinderException('This file extension is not allowed.', 'invalid_extension', 415);
        }
    }

    public function assertEntryPathAllowed(string $path, bool $directory, int $additionalFolderDepth = 0): void
    {
        $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn (string $segment): bool => $segment !== ''));
        $name = $segments === [] ? '' : $segments[array_key_last($segments)];
        $maximumLength = $directory ? $this->maxFolderNameLength : $this->maxFileNameLength;
        if (mb_strlen($name) > $maximumLength) {
            throw new SoFinderException(
                sprintf('The %s name exceeds the configured %d character limit.', $directory ? 'folder' : 'file', $maximumLength),
                $directory ? 'folder_name_too_long' : 'file_name_too_long',
                422,
            );
        }
        $folderDepth = count($segments) - ($directory ? 0 : 1) + $additionalFolderDepth;
        if ($folderDepth > $this->maxFolderDepth) {
            throw new SoFinderException(
                sprintf('The destination exceeds the configured folder depth limit of %d.', $this->maxFolderDepth),
                'folder_depth_exceeded',
                422,
            );
        }
    }

    /** @return array<string, bool|int|string|list<string>> */
    public function jsonSerialize(): array
    {
        return [
            'name' => $this->name,
            'publicUrl' => $this->publicUrl,
            'allowedExtensions' => $this->allowedExtensions,
            'allowedMimeTypes' => $this->allowedMimeTypes,
            'maxSize' => $this->maxSize,
            'readOnly' => $this->readOnly,
            'quotaBytes' => $this->quotaBytes,
            'maxFileNameLength' => $this->maxFileNameLength,
            'maxFolderNameLength' => $this->maxFolderNameLength,
            'maxFolderDepth' => $this->maxFolderDepth,
            'maxImagePixels' => $this->maxImagePixels,
            'deliveryMode' => $this->deliveryMode,
            'entryUrlConfigured' => $this->entryUrlRoute !== '',
            'maxBatchItems' => $this->maxBatchItems,
            'maxRecursiveItems' => $this->maxRecursiveItems,
            'maxArchiveItems' => $this->maxArchiveItems,
            'maxArchiveBytes' => $this->maxArchiveBytes,
            'maxImageWidth' => $this->maxImageWidth,
            'maxImageHeight' => $this->maxImageHeight,
            'animatedImagePolicy' => $this->animatedImagePolicy,
        ];
    }
}

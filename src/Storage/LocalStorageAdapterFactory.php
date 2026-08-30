<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Storage;

use SohoPHP\SoFinder\Contract\StorageAdapterFactoryInterface;
use SohoPHP\SoFinder\Contract\StorageAdapterInterface;
use SohoPHP\SoFinder\Security\PathGuard;
use SohoPHP\SoFinder\Value\ResourceType;

final class LocalStorageAdapterFactory implements StorageAdapterFactoryInterface
{
    public function __construct(
        private readonly PathGuard $pathGuard = new PathGuard(),
        private readonly int $directoryMode = 0775,
        private readonly int $fileMode = 0664,
    )
    {
    }

    public function alias(): string
    {
        return 'local';
    }

    public function create(ResourceType $resource, array $options = []): StorageAdapterInterface
    {
        return new LocalStorageAdapter($resource->root, $resource->publicUrl, $this->pathGuard, $this->directoryMode, $this->fileMode);
    }
}

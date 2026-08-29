<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Value;

use SohoPHP\SoFinder\Contract\StorageAdapterInterface;

final readonly class ResourceStorage
{
    public function __construct(
        public ResourceType $resource,
        public StorageAdapterInterface $storage,
    ) {
    }
}

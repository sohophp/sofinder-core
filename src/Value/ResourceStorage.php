<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Value;

use SohoPHP\SoFinder\Contract\StorageAdapterInterface;

final class ResourceStorage
{
    public function __construct(
        public readonly ResourceType $resource,
        public readonly StorageAdapterInterface $storage,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Contract;

use SohoPHP\SoFinder\Value\ResourceType;

interface StorageAdapterFactoryInterface
{
    public function alias(): string;

    /** @param array<string, mixed> $options */
    public function create(ResourceType $resource, array $options = []): StorageAdapterInterface;
}

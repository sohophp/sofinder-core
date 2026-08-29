<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder;

use SohoPHP\SoFinder\Exception\NotFoundException;
use SohoPHP\SoFinder\Value\ResourceStorage;

final class ResourceRegistry
{
    /** @var array<string, ResourceStorage> */
    private array $resources = [];

    /** @param iterable<ResourceStorage> $resources */
    public function __construct(iterable $resources = [])
    {
        foreach ($resources as $resource) {
            $this->resources[$resource->resource->name] = $resource;
        }
    }

    public function get(string $name): ResourceStorage
    {
        return $this->resources[$name] ?? throw new NotFoundException('The requested resource type does not exist.');
    }

    /** @return list<ResourceStorage> */
    public function all(): array
    {
        return array_values($this->resources);
    }
}

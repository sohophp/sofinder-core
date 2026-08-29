<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Event;

use SohoPHP\SoFinder\Value\ResourceType;

final readonly class OperationEvent
{
    /** @param array<string, mixed> $context */
    public function __construct(
        public string $operation,
        public ResourceType $resource,
        public string $path,
        public array $context = [],
    ) {
    }
}

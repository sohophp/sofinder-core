<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Event;

use SohoPHP\SoFinder\Value\ResourceType;

final class OperationEvent
{
    /** @param array<string, mixed> $context */
    public function __construct(
        public readonly string $operation,
        public readonly ResourceType $resource,
        public readonly string $path,
        public readonly array $context = [],
    ) {
    }
}

<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Maintenance;

final class MaintenanceResult
{
    /** @param array<string, int> $details */
    public function __construct(
        public readonly MaintenanceTask $task,
        public readonly bool $executed,
        public readonly int $processed,
        public readonly array $details = [],
    ) {
    }
}

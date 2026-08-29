<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Maintenance;

final readonly class MaintenanceResult
{
    /** @param array<string, int> $details */
    public function __construct(
        public MaintenanceTask $task,
        public bool $executed,
        public int $processed,
        public array $details = [],
    ) {
    }
}

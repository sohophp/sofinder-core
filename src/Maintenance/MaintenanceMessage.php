<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Maintenance;

final readonly class MaintenanceMessage
{
    public function __construct(public string $task)
    {
        MaintenanceTask::from($task);
    }
}

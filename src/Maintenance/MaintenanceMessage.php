<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Maintenance;

final class MaintenanceMessage
{
    public function __construct(public readonly string $task)
    {
        MaintenanceTask::from($task);
    }
}

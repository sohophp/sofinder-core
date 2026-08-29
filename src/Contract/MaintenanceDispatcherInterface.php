<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Contract;

use SohoPHP\SoFinder\Maintenance\MaintenanceTask;

interface MaintenanceDispatcherInterface
{
    public function dispatch(MaintenanceTask $task): void;
}

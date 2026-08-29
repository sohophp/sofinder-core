<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Maintenance;

final readonly class MaintenanceMessageHandler
{
    public function __construct(private MaintenanceRunner $runner)
    {
    }

    public function __invoke(MaintenanceMessage $message): void
    {
        $this->runner->run(MaintenanceTask::from($message->task));
    }
}

<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Contract;

interface QueueTelemetryProviderInterface extends QueueHealthProviderInterface
{
    /** @return array{backlog:int,failed:int} */
    public function queueTelemetry(): array;
}

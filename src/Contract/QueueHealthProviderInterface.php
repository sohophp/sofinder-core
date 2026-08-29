<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Contract;

use SohoPHP\SoFinder\Value\HealthCheckResult;

interface QueueHealthProviderInterface
{
    public function checkQueue(): HealthCheckResult;
}

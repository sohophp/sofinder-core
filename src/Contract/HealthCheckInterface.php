<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Contract;

use SohoPHP\SoFinder\Value\HealthCheckResult;

/** A secret-safe readiness check supplied by the host or a SoFinder plugin. */
interface HealthCheckInterface
{
    public function check(): HealthCheckResult;
}

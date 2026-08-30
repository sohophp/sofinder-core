<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Health;

use SohoPHP\SoFinder\Contract\HealthCheckInterface;
use SohoPHP\SoFinder\Value\HealthCheckResult;

final class HealthManager
{
    /** @param iterable<HealthCheckInterface> $checks */
    public function __construct(private readonly iterable $checks)
    {
    }

    /** @return array{status:string,checks:list<HealthCheckResult>} */
    public function report(): array
    {
        $results = [];
        $names = [];
        $overall = 'ready';
        foreach ($this->checks as $check) {
            try {
                $result = $check->check();
            } catch (\Throwable) {
                $result = new HealthCheckResult('plugin-' . substr(hash('sha256', $check::class), 0, 12), 'down', 'The health check failed.');
            }
            if (isset($names[$result->name])) {
                $result = new HealthCheckResult($result->name . '-' . substr(hash('sha256', $check::class), 0, 8), 'down', 'A health check name is duplicated.');
            }
            $names[$result->name] = true;
            $results[] = $result;
            if ($result->status === 'down') $overall = 'down';
            elseif ($result->status === 'degraded' && $overall === 'ready') $overall = 'degraded';
        }

        usort($results, static fn (HealthCheckResult $left, HealthCheckResult $right): int => $left->name <=> $right->name);

        return ['status' => $overall, 'checks' => $results];
    }
}

<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Health;

use SohoPHP\SoFinder\Contract\AtomicStateStoreInterface;
use SohoPHP\SoFinder\Contract\HealthCheckInterface;
use SohoPHP\SoFinder\Value\HealthCheckResult;

final class SharedStateHealthCheck implements HealthCheckInterface
{
    public function __construct(private readonly AtomicStateStoreInterface $state)
    {
    }

    public function check(): HealthCheckResult
    {
        $token = bin2hex(random_bytes(8));
        $written = $this->state->mutate('health', 'readiness', static fn (array $current): array => [
            'token' => $token,
            'checkedAt' => time(),
        ]);
        $read = $this->state->get('health', 'readiness');

        if (($written['token'] ?? null) !== $token || ($read['token'] ?? null) !== $token) {
            return new HealthCheckResult('shared-state', 'down', 'The shared state service did not persist an atomic readiness probe.');
        }

        return new HealthCheckResult('shared-state', 'ready', 'The shared state service accepted an atomic read/write probe.');
    }
}

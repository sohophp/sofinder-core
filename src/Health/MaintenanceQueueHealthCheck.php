<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Health;

use SohoPHP\SoFinder\Contract\HealthCheckInterface;
use SohoPHP\SoFinder\Contract\QueueHealthProviderInterface;
use SohoPHP\SoFinder\Contract\QueueTelemetryProviderInterface;
use SohoPHP\SoFinder\Contract\GaugeMetricsStoreInterface;
use SohoPHP\SoFinder\Value\HealthCheckResult;

final readonly class MaintenanceQueueHealthCheck implements HealthCheckInterface
{
    /** @param iterable<QueueHealthProviderInterface> $providers */
    public function __construct(private string $mode, private bool $dispatcherAvailable, private iterable $providers = [], private ?GaugeMetricsStoreInterface $metrics = null) {}

    public function check(): HealthCheckResult
    {
        if ($this->mode === 'messenger' && !$this->dispatcherAvailable) {
            return new HealthCheckResult('maintenance-queue', 'down', 'Messenger maintenance is enabled but its dispatcher is unavailable.');
        }
        if ($this->mode === 'messenger') {
            foreach ($this->providers as $provider) {
                if ($provider instanceof QueueTelemetryProviderInterface) {
                    $telemetry = $provider->queueTelemetry();
                    $this->metrics?->set('sofinder_queue_backlog', max(0, $telemetry['backlog']), ['queue' => 'maintenance']);
                    $this->metrics?->set('sofinder_queue_failed', max(0, $telemetry['failed']), ['queue' => 'maintenance']);
                }
                return $provider->checkQueue();
            }
            return new HealthCheckResult('maintenance-queue', 'degraded', 'The Messenger dispatcher is registered, but transport connectivity and backlog are not verified.');
        }

        return new HealthCheckResult('maintenance-queue', 'ready', sprintf('Maintenance mode is %s.', $this->mode));
    }
}

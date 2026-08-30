<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Health;

use SohoPHP\SoFinder\Contract\HealthCheckInterface;
use SohoPHP\SoFinder\Contract\LocalPathProviderInterface;
use SohoPHP\SoFinder\Contract\StorageHealthProbeInterface;
use SohoPHP\SoFinder\Contract\MetricsStoreInterface;
use SohoPHP\SoFinder\ResourceRegistry;
use SohoPHP\SoFinder\Value\HealthCheckResult;
use SohoPHP\SoFinder\Value\ListQuery;

final class StorageHealthCheck implements HealthCheckInterface
{
    public function __construct(private readonly ResourceRegistry $resources, private readonly ?MetricsStoreInterface $metrics = null)
    {
    }

    public function check(): HealthCheckResult
    {
        $count = 0;
        foreach ($this->resources->all() as $resource) {
            ++$count;
            $started = hrtime(true);
            try {
                if ($resource->storage instanceof StorageHealthProbeInterface) $resource->storage->checkStorage();
                else $resource->storage->list(new ListQuery(limit: 1));
                if (!$resource->resource->readOnly && $resource->storage instanceof LocalPathProviderInterface) {
                    $root = $resource->storage->absolutePath('');
                    if (!is_dir($root) || !is_writable($root)) {
                        throw new \RuntimeException('The local storage root is not writable.');
                    }
                }
            } catch (\Throwable) {
                $this->recordLatency($resource->resource->name, $started, 'down');
                return new HealthCheckResult('storage', 'down', sprintf('Storage resource %s is unavailable.', $resource->resource->name));
            }
            $this->recordLatency($resource->resource->name, $started, 'ready');
        }

        return new HealthCheckResult('storage', 'ready', sprintf('%d storage resource(s) responded.', $count));
    }

    private function recordLatency(string $resource, int $started, string $result): void
    {
        $labels = ['operation' => 'list', 'resource' => substr($resource, 0, 64), 'result' => $result];
        $milliseconds = max(0, (int) round((hrtime(true) - $started) / 1_000_000));
        $this->metrics?->increment('sofinder_storage_duration_milliseconds_total', $labels, $milliseconds);
        $this->metrics?->increment('sofinder_storage_duration_observations_total', $labels);
    }
}

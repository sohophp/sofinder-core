<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\State;

use SohoPHP\SoFinder\Contract\AtomicStateStoreInterface;
use SohoPHP\SoFinder\Contract\StorageUsageProviderInterface;
use SohoPHP\SoFinder\Contract\UsageTrackerInterface;
use SohoPHP\SoFinder\Exception\SoFinderException;
use SohoPHP\SoFinder\Value\ResourceStorage;

final class SharedUsageTracker implements UsageTrackerInterface
{
    public function __construct(private readonly AtomicStateStoreInterface $state)
    {
    }

    public function usage(ResourceStorage $resource): int
    {
        $state = $this->state->mutate('usage', $this->key($resource), function (array $state) use ($resource): array {
            if (!isset($state['bytes']) || ($state['dirty'] ?? true) !== false) return ['bytes' => $this->scan($resource), 'dirty' => false];
            return $state;
        });
        return max(0, (int) ($state['bytes'] ?? 0));
    }

    public function recalculate(ResourceStorage $resource): int
    {
        $state = $this->state->mutate('usage', $this->key($resource), fn (array $state): array => ['bytes' => $this->scan($resource), 'dirty' => false]);
        return (int) $state['bytes'];
    }

    public function mutate(ResourceStorage $resource, callable $operation): mixed
    {
        $resultValue = new \stdClass();
        $this->state->mutate('usage', $this->key($resource), function (array $state) use ($resource, $operation, $resultValue): array {
            $current = isset($state['bytes']) && ($state['dirty'] ?? true) === false ? max(0, (int) $state['bytes']) : $this->scan($resource);
            $result = $operation($current);
            if (!is_array($result) || !array_key_exists('value', $result) || !isset($result['delta']) || !is_int($result['delta'])) {
                throw new \LogicException('A tracked storage mutation must return value and integer delta fields.');
            }
            $resultValue->value = $result['value'];
            return ['bytes' => max(0, $current + $result['delta']), 'dirty' => false];
        });

        if (!property_exists($resultValue, 'value')) throw new \LogicException('The shared usage mutation did not complete.');

        return $resultValue->value;
    }

    private function scan(ResourceStorage $resource): int
    {
        if (!$resource->storage instanceof StorageUsageProviderInterface) {
            throw new SoFinderException('This storage adapter cannot calculate usage; provide a persisted usage baseline before enabling quotas.', 'usage_scan_unsupported', 501);
        }
        return $resource->storage->usage();
    }

    private function key(ResourceStorage $resource): string
    {
        return $resource->resource->name . "\0" . $resource->resource->root;
    }
}

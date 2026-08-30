<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Observability;

use SohoPHP\SoFinder\Contract\AtomicStateStoreInterface;
use SohoPHP\SoFinder\Contract\GaugeMetricsStoreInterface;

/** Cluster-wide counters backed by the configured Redis or PDO atomic state store. */
final class SharedMetricsStore implements GaugeMetricsStoreInterface
{
    public function __construct(private readonly AtomicStateStoreInterface $state) {}

    public function increment(string $name, array $labels = [], int $amount = 1): void
    {
        $this->validate($name, $labels);
        ksort($labels);
        $key = hash('sha256', json_encode([$name, $labels], JSON_THROW_ON_ERROR));
        $this->state->mutate('metrics', 'global', static function (array $state) use ($key, $name, $labels, $amount): array {
            $state[$key] = ['name' => $name, 'labels' => $labels, 'value' => max(0, (int) ($state[$key]['value'] ?? 0) + $amount)];

            return $state;
        });
    }

    public function snapshot(): array
    {
        $values = array_values(array_filter($this->state->get('metrics', 'global'), static fn (mixed $item): bool => is_array($item) && is_string($item['name'] ?? null) && is_array($item['labels'] ?? null) && is_int($item['value'] ?? null)));
        usort($values, static fn (array $left, array $right): int => [$left['name'], json_encode($left['labels'])] <=> [$right['name'], json_encode($right['labels'])]);

        return $values;
    }

    public function set(string $name, int $value, array $labels = []): void
    {
        $this->validate($name, $labels);
        ksort($labels);
        $key = hash('sha256', json_encode([$name, $labels], JSON_THROW_ON_ERROR));
        $this->state->mutate('metrics', 'global', static function (array $state) use ($key, $name, $labels, $value): array {
            $state[$key] = ['name' => $name, 'labels' => $labels, 'value' => max(0, $value)];
            return $state;
        });
    }

    /** @param array<string,string> $labels */
    private function validate(string $name, array $labels): void
    {
        if (preg_match('/^sofinder_[a-z][a-z0-9_]{1,63}$/D', $name) !== 1 || count($labels) > 8) throw new \InvalidArgumentException('The metric name or labels are invalid.');
        foreach ($labels as $key => $value) if (preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $key) !== 1 || strlen($value) > 64 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) throw new \InvalidArgumentException('The metric name or labels are invalid.');
    }
}

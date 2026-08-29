<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Contract;

interface GaugeMetricsStoreInterface extends MetricsStoreInterface
{
    /** @param array<string,string> $labels */
    public function set(string $name, int $value, array $labels = []): void;
}

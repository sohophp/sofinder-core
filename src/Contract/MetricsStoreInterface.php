<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Contract;

interface MetricsStoreInterface
{
    /** @param array<string,string> $labels */
    public function increment(string $name, array $labels = [], int $amount = 1): void;

    /** @return list<array{name:string,labels:array<string,string>,value:int}> */
    public function snapshot(): array;
}

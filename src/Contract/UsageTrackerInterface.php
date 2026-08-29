<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Contract;

use SohoPHP\SoFinder\Value\ResourceStorage;

interface UsageTrackerInterface
{
    public function usage(ResourceStorage $resource): int;

    public function recalculate(ResourceStorage $resource): int;

    /**
     * The callback runs while the resource usage lock is held and must return
     * the operation result together with its exact storage-byte delta.
     *
     * @template T
     * @param callable(int):array{value:T,delta:int} $operation
     * @return T
     */
    public function mutate(ResourceStorage $resource, callable $operation): mixed;
}

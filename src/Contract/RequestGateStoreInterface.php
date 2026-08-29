<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Contract;

interface RequestGateStoreInterface
{
    /**
     * @param callable(array<string,mixed>):array<string,mixed> $callback
     * @return array<string,mixed>
     */
    public function mutate(string $group, string $actor, callable $callback): array;
}

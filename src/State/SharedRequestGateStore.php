<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\State;

use SohoPHP\SoFinder\Contract\AtomicStateStoreInterface;
use SohoPHP\SoFinder\Contract\RequestGateStoreInterface;

final class SharedRequestGateStore implements RequestGateStoreInterface
{
    public function __construct(private readonly AtomicStateStoreInterface $state)
    {
    }

    public function mutate(string $group, string $actor, callable $callback): array
    {
        return $this->state->mutate('request-gate', $group . "\0" . $actor, $callback);
    }
}

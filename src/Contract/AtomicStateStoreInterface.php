<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Contract;

/** Shared atomic JSON state used by database and Redis-backed integrations. */
interface AtomicStateStoreInterface
{
    /** @return array<string,mixed> */
    public function get(string $namespace, string $key): array;

    /**
     * The callback runs at most once while an exclusive key lock is held.
     *
     * @param callable(array<string,mixed>):array<string,mixed> $callback
     * @return array<string,mixed>
     */
    public function mutate(string $namespace, string $key, callable $callback): array;
}

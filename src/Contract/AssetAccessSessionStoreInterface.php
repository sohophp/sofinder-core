<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Contract;

interface AssetAccessSessionStoreInterface
{
    /** @param array<string,mixed> $session */
    public function put(string $id, array $session): void;
    /** @return array<string,mixed>|null */
    public function get(string $id): ?array;
    public function remove(string $id): void;
}

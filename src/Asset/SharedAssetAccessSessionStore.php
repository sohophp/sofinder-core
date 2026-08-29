<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Asset;

use SohoPHP\SoFinder\Contract\AssetAccessSessionStoreInterface;
use SohoPHP\SoFinder\Contract\AtomicStateStoreInterface;

final readonly class SharedAssetAccessSessionStore implements AssetAccessSessionStoreInterface
{
    public function __construct(private AtomicStateStoreInterface $state) {}
    public function put(string $id, array $session): void { $this->state->mutate('asset_access_sessions', hash('sha256', $id), static fn (array $_): array => $session); }
    public function get(string $id): ?array { $value = $this->state->get('asset_access_sessions', hash('sha256', $id)); return $value === [] ? null : $value; }
    public function remove(string $id): void { $this->state->mutate('asset_access_sessions', hash('sha256', $id), static fn (array $_): array => ['revoked' => true]); }
}

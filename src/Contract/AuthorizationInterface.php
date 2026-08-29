<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Contract;

use SohoPHP\SoFinder\Value\ResourceType;

interface AuthorizationInterface
{
    public function isAuthenticated(): bool;

    public function isGranted(string $operation, ResourceType $resource, string $path): bool;
}

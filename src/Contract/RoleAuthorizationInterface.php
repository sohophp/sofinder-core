<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Contract;

interface RoleAuthorizationInterface
{
    public function isGranted(string $role): bool;
}

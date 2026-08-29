<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Contract;

interface StorageUsageProviderInterface
{
    public function usage(): int;
}

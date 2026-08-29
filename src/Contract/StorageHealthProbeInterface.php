<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Contract;

interface StorageHealthProbeInterface
{
    /** Throw when the configured storage cannot serve requests. */
    public function checkStorage(): void;
}

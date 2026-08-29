<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Contract;

interface LocalPathProviderInterface
{
    public function absolutePath(string $path): string;
}

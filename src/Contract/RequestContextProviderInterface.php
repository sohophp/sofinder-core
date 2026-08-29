<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Contract;

use SohoPHP\SoFinder\Value\RequestContext;

interface RequestContextProviderInterface
{
    public function current(): ?RequestContext;
}

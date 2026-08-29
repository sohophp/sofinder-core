<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Contract;

use SohoPHP\SoFinder\Value\WorkspaceContext;
use SohoPHP\SoFinder\Value\RequestContext;

interface WorkspaceResolverInterface
{
    public function resolve(RequestContext $request): WorkspaceContext;
}

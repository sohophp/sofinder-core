<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Contract;

use SohoPHP\SoFinder\Value\WorkspaceContext;
use SohoPHP\SoFinder\Value\RequestContext;

/** Supplies trusted host navigation targets for users who can access multiple Workspaces. */
interface WorkspaceOptionProviderInterface
{
    /** @return list<array{id:string,label:string,url:string}> */
    public function options(RequestContext $request, WorkspaceContext $current): array;
}

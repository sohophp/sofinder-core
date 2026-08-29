<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Contract;

use SohoPHP\SoFinder\Value\AssetSearchQuery;
use SohoPHP\SoFinder\Value\AssetSearchResult;
use SohoPHP\SoFinder\Value\WorkspaceContext;

interface AssetSearchProviderInterface
{
    public function search(WorkspaceContext $workspace, AssetSearchQuery $query): AssetSearchResult;
}

<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Contract;

use SohoPHP\SoFinder\Value\AssetRecord;
use SohoPHP\SoFinder\Value\Entry;
use SohoPHP\SoFinder\Value\WorkspaceContext;

/** Optional plugin contract; SoFinder core does not retain file versions. */
interface AssetVersionProviderInterface
{
    /** @return array{items:list<array{id:string,version:string,size:int,modifiedAt:int,label:?string}>,nextCursor:?string} */
    public function list(WorkspaceContext $workspace, AssetRecord $asset, int $limit = 50, ?string $cursor = null): array;
    public function restore(WorkspaceContext $workspace, AssetRecord $asset, string $versionId): Entry;
}

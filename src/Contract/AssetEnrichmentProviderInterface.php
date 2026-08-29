<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Contract;

use SohoPHP\SoFinder\Value\AssetRecord;
use SohoPHP\SoFinder\Value\Entry;
use SohoPHP\SoFinder\Value\WorkspaceContext;

/** Optional suggestion-only contract; hosts decide whether generated metadata is persisted. */
interface AssetEnrichmentProviderInterface
{
    /** @return array{alt:?string,title:?string,tags:list<string>,confidence:?float,provider:string} */
    public function suggest(WorkspaceContext $workspace, AssetRecord $asset, Entry $entry, ?string $locale = null): array;
}

<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Contract;

use SohoPHP\SoFinder\Value\Entry;
use SohoPHP\SoFinder\Value\ResourceType;
use SohoPHP\SoFinder\Value\ShareDescriptor;

/** Optional host policy for stable or application-owned share links. */
interface ShareLinkProviderInterface
{
    public function share(ResourceType $resource, Entry $entry): ?ShareDescriptor;
}

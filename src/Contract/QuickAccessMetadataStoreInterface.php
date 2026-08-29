<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Contract;

interface QuickAccessMetadataStoreInterface extends MetadataStoreInterface
{
    public function setQuickAccess(string $actor, string $resource, string $path, bool $pinned): void;
}

<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Contract;

use SohoPHP\SoFinder\Value\Entry;
use SohoPHP\SoFinder\Value\ResourceType;

/** Adds host-specific values, such as a database record ID, to route URL templates. */
interface EntryUrlContextProviderInterface
{
    /** @return array<string, string|int|float|bool|null> */
    public function context(ResourceType $resource, Entry $entry): array;
}

<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Contract;

use SohoPHP\SoFinder\Value\Entry;
use SohoPHP\SoFinder\Value\ResourceType;

interface EntryUrlGeneratorInterface
{
    public function generate(ResourceType $resource, Entry $entry): ?string;
}

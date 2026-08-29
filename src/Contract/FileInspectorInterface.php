<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Contract;

use SohoPHP\SoFinder\Value\InspectedFile;
use SohoPHP\SoFinder\Value\ResourceType;

interface FileInspectorInterface
{
    public function inspect(string $path, string $fileName, ResourceType $resource): InspectedFile;
}

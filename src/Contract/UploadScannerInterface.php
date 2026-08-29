<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Contract;

use SohoPHP\SoFinder\Value\InspectedFile;
use SohoPHP\SoFinder\Value\ResourceType;

/** A synchronous, fail-closed scanner for files held in the private quarantine. */
interface UploadScannerInterface
{
    /**
     * Throw a SoFinderException with a stable code when the file must not be published.
     * Implementations must not move, rename or retain the quarantine file.
     */
    public function scan(string $path, string $fileName, ResourceType $resource, InspectedFile $inspection): void;
}

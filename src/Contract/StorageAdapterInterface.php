<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Contract;

use SohoPHP\SoFinder\Value\Entry;
use SohoPHP\SoFinder\Value\ListQuery;
use SohoPHP\SoFinder\Value\ListingPage;
use SohoPHP\SoFinder\Value\StorageCapabilities;

interface StorageAdapterInterface
{
    public function list(ListQuery $query): ListingPage;

    public function capabilities(): StorageCapabilities;

    public function entry(string $path): Entry;

    public function createDirectory(string $path): Entry;

    /** @param resource $stream */
    public function writeStream(string $path, mixed $stream, bool $overwrite = false): Entry;

    /** @return resource */
    public function readStream(string $path): mixed;

    public function move(string $source, string $destination, bool $overwrite = false): Entry;

    public function copy(string $source, string $destination, bool $overwrite = false): Entry;

    public function delete(string $path): void;

    public function publicUrl(string $path): ?string;

    public function size(string $path): int;
}

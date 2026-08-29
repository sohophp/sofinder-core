<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Contract;

use SohoPHP\SoFinder\Value\Entry;
use SohoPHP\SoFinder\Value\ResourceStorage;
use SohoPHP\SoFinder\Value\TrashItem;

interface RecycleBinInterface
{
    /** @return array{item:TrashItem,purgedItems:int,purgedBytes:int} */
    public function put(ResourceStorage $resource, string $path): array;

    /** @return list<TrashItem> */
    public function list(?string $resource = null): array;

    /** @return array{usedItems:int,usedBytes:int,maxItems:int,maxBytes:int} */
    public function statistics(): array;

    public function get(string $id): TrashItem;

    public function restore(ResourceStorage $resource, string $id, string $conflict = 'cancel'): Entry;

    public function permanentlyDelete(string $id): void;

    public function purgeExpired(?int $limit = null): int;
}

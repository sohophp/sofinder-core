<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Value;

final class ListingPage
{
    /** @param list<Entry> $entries */
    public function __construct(
        public readonly array $entries,
        public readonly ?int $total,
        public readonly int $offset,
        public readonly int $limit,
        public readonly ?string $nextCursor = null,
    ) {
    }
}

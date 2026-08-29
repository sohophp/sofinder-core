<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Value;

final readonly class ListingPage
{
    /** @param list<Entry> $entries */
    public function __construct(
        public array $entries,
        public ?int $total,
        public int $offset,
        public int $limit,
        public ?string $nextCursor = null,
    ) {
    }
}

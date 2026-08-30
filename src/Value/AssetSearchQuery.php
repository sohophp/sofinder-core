<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Value;

final class AssetSearchQuery
{
    /**
     * @param list<string> $resources
     * @param list<string> $fields
     * @param list<string> $tags
     * @param list<string> $extensions
     */
    public function __construct(
        public readonly string $keyword = '',
        public readonly array $resources = [],
        public readonly string $path = '',
        public readonly array $fields = ['name', 'title', 'alt', 'tags'],
        public readonly array $tags = [],
        public readonly array $extensions = [],
        public readonly string $type = 'all',
        public readonly ?int $minimumSize = null,
        public readonly ?int $maximumSize = null,
        public readonly ?int $modifiedAfter = null,
        public readonly ?int $modifiedBefore = null,
        public readonly int $offset = 0,
        public readonly int $limit = 50,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Value;

final readonly class AssetSearchQuery
{
    /**
     * @param list<string> $resources
     * @param list<string> $fields
     * @param list<string> $tags
     * @param list<string> $extensions
     */
    public function __construct(
        public string $keyword = '',
        public array $resources = [],
        public string $path = '',
        public array $fields = ['name', 'title', 'alt', 'tags'],
        public array $tags = [],
        public array $extensions = [],
        public string $type = 'all',
        public ?int $minimumSize = null,
        public ?int $maximumSize = null,
        public ?int $modifiedAfter = null,
        public ?int $modifiedBefore = null,
        public int $offset = 0,
        public int $limit = 50,
    ) {
    }
}

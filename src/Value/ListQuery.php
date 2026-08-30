<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Value;

final class ListQuery
{
    public readonly string $sort;
    public readonly string $direction;
    public readonly int $offset;
    public readonly int $limit;

    public function __construct(
        public readonly string $path = '',
        public readonly string $search = '',
        string $sort = 'name',
        string $direction = 'asc',
        int $offset = 0,
        int $limit = 100,
        public readonly ?string $cursor = null,
        /** @var list<string>|null */
        public readonly ?array $onlyPaths = null,
        public readonly ?\Closure $filter = null,
    ) {
        $this->sort = in_array($sort, ['name', 'size', 'type', 'modified'], true) ? $sort : 'name';
        $this->direction = strtolower($direction) === 'desc' ? 'desc' : 'asc';
        $this->offset = max(0, $offset);
        $this->limit = max(1, min($limit, 500));
    }
}

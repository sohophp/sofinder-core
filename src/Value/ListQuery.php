<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Value;

final readonly class ListQuery
{
    public string $sort;
    public string $direction;
    public int $offset;
    public int $limit;

    public function __construct(
        public string $path = '',
        public string $search = '',
        string $sort = 'name',
        string $direction = 'asc',
        int $offset = 0,
        int $limit = 100,
        public ?string $cursor = null,
        /** @var list<string>|null */
        public ?array $onlyPaths = null,
        public ?\Closure $filter = null,
    ) {
        $this->sort = in_array($sort, ['name', 'size', 'type', 'modified'], true) ? $sort : 'name';
        $this->direction = strtolower($direction) === 'desc' ? 'desc' : 'asc';
        $this->offset = max(0, $offset);
        $this->limit = max(1, min($limit, 500));
    }
}

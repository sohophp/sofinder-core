<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Value;

final class AssetSearchResult implements \JsonSerializable
{
    /**
     * @param list<array{resource:string,entry:Entry,assetId:?string,metadata:array{alt:?string,altTranslations:array<string,string>,title:?string,tags:list<string>,version:int,updatedAt:int}}> $items
     * @param array{resources:array<string,int>,types:array<string,int>,extensions:array<string,int>} $facets
     */
    public function __construct(
        public readonly array $items,
        public readonly int $total,
        public readonly int $offset,
        public readonly int $limit,
        public readonly int $scanned,
        public readonly bool $truncated,
        public readonly array $facets,
    ) {
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'items' => $this->items,
            'total' => $this->total,
            'offset' => $this->offset,
            'limit' => $this->limit,
            'scanned' => $this->scanned,
            'truncated' => $this->truncated,
            'facets' => $this->facets,
        ];
    }
}

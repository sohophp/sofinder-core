<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Storage;

use SohoPHP\SoFinder\Contract\StorageAdapterInterface;
use SohoPHP\SoFinder\Exception\SoFinderException;
use SohoPHP\SoFinder\Value\Entry;
use SohoPHP\SoFinder\Value\ListQuery;

final readonly class StoragePaginator
{
    /** @return list<Entry> */
    public function all(StorageAdapterInterface $storage, ListQuery $query): array
    {
        $entries = [];
        $offset = 0;
        $cursor = $query->cursor;
        $seenCursors = [];
        do {
            $page = $storage->list(new ListQuery(
                $query->path,
                $query->search,
                $query->sort,
                $query->direction,
                $offset,
                $query->limit,
                $cursor,
                $query->onlyPaths,
                $query->filter,
            ));
            array_push($entries, ...$page->entries);
            $offset += $page->limit;
            $cursor = $page->nextCursor;
            if ($cursor !== null) {
                if (isset($seenCursors[$cursor])) {
                    throw new SoFinderException('The storage adapter returned a repeated pagination cursor.', 'invalid_storage_page', 500);
                }
                $seenCursors[$cursor] = true;
            }
        } while ($cursor !== null || ($page->total !== null && $offset < $page->total));

        return $entries;
    }
}

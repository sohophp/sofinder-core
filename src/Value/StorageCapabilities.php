<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Value;

final class StorageCapabilities implements \JsonSerializable
{
    public function __construct(
        public readonly bool $search = true,
        public readonly bool $sort = true,
        public readonly bool $cursorPagination = false,
        public readonly bool $atomicMove = false,
        public readonly bool $nativeCopy = false,
        public readonly bool $recoverableDelete = false,
        public readonly bool $publicUrl = false,
    ) {
    }

    /** @return array<string, bool> */
    public function jsonSerialize(): array
    {
        return [
            'search' => $this->search,
            'sort' => $this->sort,
            'cursorPagination' => $this->cursorPagination,
            'atomicMove' => $this->atomicMove,
            'nativeCopy' => $this->nativeCopy,
            'recoverableDelete' => $this->recoverableDelete,
            'publicUrl' => $this->publicUrl,
        ];
    }
}

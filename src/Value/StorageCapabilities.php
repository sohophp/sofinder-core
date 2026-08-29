<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Value;

final readonly class StorageCapabilities implements \JsonSerializable
{
    public function __construct(
        public bool $search = true,
        public bool $sort = true,
        public bool $cursorPagination = false,
        public bool $atomicMove = false,
        public bool $nativeCopy = false,
        public bool $recoverableDelete = false,
        public bool $publicUrl = false,
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

<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Contract;

interface ChunkUploadStoreInterface
{
    /**
     * @param resource $stream
     * @param array{resource?:string,path?:string,name?:string,overwrite?:bool,autoRename?:bool,workspace?:string} $context
     * @return array{complete:bool,path?:string,size?:int}
     */
    public function accept(string $id, int $index, int $total, mixed $stream, int $maximumFileBytes, array $context = []): array;

    /** @return array{id:string,total:int,received:list<int>,complete:bool,resource:string,path:string,name:string,overwrite:bool,autoRename:bool,workspace:string,updatedAt:int} */
    public function status(string $id): array;

    public function discard(string $id): void;

    public function cleanupExpired(bool $allActors = false, ?int $limit = null): int;
}

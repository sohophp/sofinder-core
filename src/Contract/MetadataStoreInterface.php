<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Contract;

interface MetadataStoreInterface
{
    /** @return array{favorites:list<string>,quickAccess?:list<string>,tags:array<string,list<string>>,recent:list<array{path:string,touchedAt:int}>} */
    public function get(string $actor, string $resource): array;

    public function setFavorite(string $actor, string $resource, string $path, bool $favorite): void;

    /** @param list<string> $tags */
    public function setTags(string $actor, string $resource, string $path, array $tags): void;

    public function touch(string $actor, string $resource, string $path, int $timestamp): void;

    public function movePath(string $actor, string $resource, string $source, string $destination): void;

    public function deletePath(string $actor, string $resource, string $path): void;
}

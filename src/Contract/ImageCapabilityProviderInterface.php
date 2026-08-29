<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Contract;

interface ImageCapabilityProviderInterface
{
    /** @return list<array{format:string,extensions:list<string>,mimes:list<string>,processor:string,read:bool,edit:bool,thumbnail:bool,webEmbeddable:bool}> */
    public function capabilities(): array;

    public function isWebEmbeddable(string $mimeType): bool;

    public function supportsExtension(string $extension): bool;

    public function driver(): string;

    public function cacheVersion(): string;
}

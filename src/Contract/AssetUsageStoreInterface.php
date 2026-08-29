<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Contract;

interface AssetUsageStoreInterface
{
    /** @return list<array{referenceId:string,label:string,url:?string,context:?string,updatedAt:int}> */
    public function list(string $workspace, string $assetId): array;

    /** @return array{referenceId:string,label:string,url:?string,context:?string,updatedAt:int} */
    public function put(string $workspace, string $assetId, string $referenceId, string $label, ?string $url, ?string $context): array;

    public function remove(string $workspace, string $assetId, string $referenceId): void;
}

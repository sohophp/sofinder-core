<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Contract;

/**
 * Allows a trusted host Workspace resolver/storage mapper to expose only the
 * physical roots needed by the security audit. It is never used for routing.
 */
interface WorkspaceStorageAuditProviderInterface
{
    /**
     * @return list<array{workspace:string,resource:string,root:string,writable?:bool}>
     */
    public function workspaceStorageMappings(): array;
}

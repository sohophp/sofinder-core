<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Contract;

/** Optional, secret-safe security and connectivity findings for remote storage. */
interface StorageAuditProviderInterface
{
    /** @return list<array{severity:'warning'|'critical',message:string}> */
    public function auditStorage(): array;
}

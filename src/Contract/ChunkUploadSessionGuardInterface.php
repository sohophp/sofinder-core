<?php
declare(strict_types=1);

namespace SohoPHP\SoFinder\Contract;

/** Optional guard covering assembly, publication and session disposal together. */
interface ChunkUploadSessionGuardInterface
{
    /**
     * Nested operations on the same store must be reentrant.
     * @template T
     * @param callable():T $operation
     * @return T
     */
    public function withSessionLock(string $id, callable $operation): mixed;
}

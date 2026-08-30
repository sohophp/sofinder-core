<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Preview;

/** Framework-neutral queue message translated by each full-stack bridge. */
final class DocumentPreviewMessage
{
    public function __construct(public readonly string $jobId)
    {
        if ($jobId === '' || strlen($jobId) > 128) {
            throw new \InvalidArgumentException('The document preview job id is invalid.');
        }
    }
}

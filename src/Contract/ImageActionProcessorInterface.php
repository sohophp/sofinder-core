<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Contract;

/** Optional whole-operation processor for hosts with explicit image-edit policies. */
interface ImageActionProcessorInterface extends ImageProcessorInterface
{
    /** @return list<string> Supported action types advertised to image-edit clients. */
    public function supportedActions(): array;

    /** @return array{width:positive-int,height:positive-int} Maximum requested resize dimensions. */
    public function resizeLimits(): array;

    /** Explicit opt-in; returning true permits the host pipeline to handle animated input. */
    public function allowsAnimatedEditing(string $source): bool;

    /**
     * Process temporary files only. Validate actions, dimensions and encoding policy;
     * never modify the source. The manager still authorizes and validates final storage.
     * @param list<array<string,mixed>> $actions
     */
    public function applyActions(string $source, string $destination, array $actions): void;
}

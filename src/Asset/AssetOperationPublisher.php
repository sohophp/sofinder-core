<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Asset;

use Psr\EventDispatcher\EventDispatcherInterface;
use SohoPHP\SoFinder\Contract\AssetCatalogInterface;
use SohoPHP\SoFinder\Event\AssetOperationEvent;
use SohoPHP\SoFinder\Exception\SoFinderException;
use SohoPHP\SoFinder\ResourceRegistry;
use SohoPHP\SoFinder\Workspace\WorkspaceProvider;
use SohoPHP\SoFinder\Value\RequestContext;

final readonly class AssetOperationPublisher
{
    public function __construct(private EventDispatcherInterface $events, private WorkspaceProvider $workspaces, private ResourceRegistry $resources, private AssetCatalogInterface $assets, private bool $assetsEnabled)
    {
    }

    /** @param array<string,mixed> $attributes */
    public function dispatch(string $operationId, string $operation, string $phase, string $resource, string $path, ?string $sourcePath = null, ?string $assetId = null, array $attributes = [], ?RequestContext $context = null): void
    {
        $workspace = $this->workspaces->assertResource($resource, $context);
        $record = $this->assetsEnabled ? $this->assets->resolve($workspace->id, $resource, $path) : null;
        $this->events->dispatch(new AssetOperationEvent($operationId, $operation, $phase, $workspace, $this->resources->get($resource)->resource, $path, $sourcePath, $assetId ?? $record?->id, $attributes));
    }

    public function operationId(): string { return bin2hex(random_bytes(16)); }
    public function errorCode(\Throwable $error): string { return $error instanceof SoFinderException ? $error->errorCode : 'operation_failed'; }
}

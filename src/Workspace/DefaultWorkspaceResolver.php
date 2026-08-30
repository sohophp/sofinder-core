<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Workspace;

use SohoPHP\SoFinder\Contract\ActorProviderInterface;
use SohoPHP\SoFinder\Contract\WorkspaceResolverInterface;
use SohoPHP\SoFinder\ResourceRegistry;
use SohoPHP\SoFinder\Value\RequestContext;
use SohoPHP\SoFinder\Value\ResourceStorage;
use SohoPHP\SoFinder\Value\WorkspaceContext;

/** Framework-neutral single-workspace resolver used by default host integrations. */
final class DefaultWorkspaceResolver implements WorkspaceResolverInterface
{
    public function __construct(
        private readonly ActorProviderInterface $actors,
        private readonly ResourceRegistry $resources,
        private readonly string $default = 'main',
    ) {
    }

    public function resolve(RequestContext $request): WorkspaceContext
    {
        return new WorkspaceContext(
            $this->default,
            $this->actors->actorId(),
            array_map(static fn (ResourceStorage $resource): string => $resource->resource->name, $this->resources->all()),
        );
    }
}

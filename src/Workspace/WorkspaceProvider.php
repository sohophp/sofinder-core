<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Workspace;

use SohoPHP\SoFinder\Contract\WorkspaceResolverInterface;
use SohoPHP\SoFinder\Contract\RequestContextProviderInterface;
use SohoPHP\SoFinder\Exception\AccessDeniedException;
use SohoPHP\SoFinder\Value\RequestContext;
use SohoPHP\SoFinder\Value\WorkspaceContext;

final class WorkspaceProvider
{
    public function __construct(private readonly WorkspaceResolverInterface $resolver, private readonly RequestContextProviderInterface $requests)
    {
    }

    public function current(?RequestContext $request = null): WorkspaceContext
    {
        $request ??= $this->context();
        if ($request === null) throw new AccessDeniedException('A request context is required to resolve the workspace.');

        return $this->resolver->resolve($request);
    }

    public function context(): ?RequestContext
    {
        return $this->requests->current();
    }

    public function assertResource(string $resource, ?RequestContext $request = null): WorkspaceContext
    {
        $workspace = $this->current($request);
        if ($workspace->resources !== [] && !in_array($resource, $workspace->resources, true)) {
            throw new AccessDeniedException('The selected resource is not available in this workspace.');
        }

        return $workspace;
    }
}

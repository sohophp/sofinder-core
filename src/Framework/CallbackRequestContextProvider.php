<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Framework;

use SohoPHP\SoFinder\Contract\RequestContextProviderInterface;
use SohoPHP\SoFinder\Value\RequestContext;

final class CallbackRequestContextProvider implements RequestContextProviderInterface
{
    private readonly \Closure $provider;

    /** @param callable():?RequestContext $provider */
    public function __construct(callable $provider)
    {
        $this->provider = \Closure::fromCallable($provider);
    }

    public function current(): ?RequestContext
    {
        return ($this->provider)();
    }
}

<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Framework;

use SohoPHP\SoFinder\Contract\RequestContextProviderInterface;
use SohoPHP\SoFinder\Value\RequestContext;

/** Request-local context stack for synchronous PSR dispatchers. */
final class ScopedRequestContextProvider implements RequestContextProviderInterface
{
    /** @var list<RequestContext> */
    private array $contexts = [];

    public function current(): ?RequestContext
    {
        return $this->contexts === [] ? null : $this->contexts[array_key_last($this->contexts)];
    }

    public function push(RequestContext $context): void
    {
        $this->contexts[] = $context;
    }

    public function pop(): void
    {
        if ($this->contexts === []) throw new \LogicException('The SoFinder request context stack is empty.');
        array_pop($this->contexts);
    }
}

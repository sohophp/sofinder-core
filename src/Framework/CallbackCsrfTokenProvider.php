<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Framework;

use SohoPHP\SoFinder\Contract\CsrfTokenProviderInterface;
use SohoPHP\SoFinder\Value\RequestContext;

final class CallbackCsrfTokenProvider implements CsrfTokenProviderInterface
{
    private readonly \Closure $issuer;
    private readonly \Closure $validator;

    /**
     * @param callable(RequestContext):string $issuer
     * @param callable(RequestContext,string):bool $validator
     */
    public function __construct(callable $issuer, callable $validator)
    {
        $this->issuer = \Closure::fromCallable($issuer);
        $this->validator = \Closure::fromCallable($validator);
    }

    public function token(RequestContext $context): string
    {
        $token = ($this->issuer)($context);
        if ($token === '') {
            throw new \RuntimeException('The host CSRF provider returned an empty token.');
        }

        return $token;
    }

    public function isValid(RequestContext $context, string $token): bool
    {
        return $token !== '' && ($this->validator)($context, $token);
    }
}

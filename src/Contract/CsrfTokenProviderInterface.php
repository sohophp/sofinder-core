<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Contract;

use SohoPHP\SoFinder\Value\RequestContext;

interface CsrfTokenProviderInterface
{
    public function token(RequestContext $context): string;

    public function isValid(RequestContext $context, string $token): bool;
}

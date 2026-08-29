<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Contract;

interface EndpointUrlGeneratorInterface
{
    /** @param array<string, string|int|float|bool|null> $parameters */
    public function generate(string $endpoint, array $parameters = [], bool $absolute = false): string;
}

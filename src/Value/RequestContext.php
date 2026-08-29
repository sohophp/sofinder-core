<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Value;

/** Trusted, framework-independent request facts exposed to host integrations. */
final readonly class RequestContext
{
    /**
     * @param array<string, string|list<string>> $headers
     * @param array<string, mixed> $query
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        public array $headers = [],
        public array $query = [],
        public array $attributes = [],
        public string $basePath = '',
        public string $schemeAndHost = '',
    ) {
    }

    public function header(string $name, string $default = ''): string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) !== 0) {
                continue;
            }

            return is_array($value) ? (string) ($value[0] ?? $default) : $value;
        }

        return $default;
    }

    public function query(string $name, mixed $default = null): mixed
    {
        return $this->query[$name] ?? $default;
    }

    public function attribute(string $name, mixed $default = null): mixed
    {
        return $this->attributes[$name] ?? $default;
    }
}

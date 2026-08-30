<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Framework;

use SohoPHP\SoFinder\Contract\EntryUrlContextProviderInterface;
use SohoPHP\SoFinder\Contract\EntryUrlGeneratorInterface;
use SohoPHP\SoFinder\Exception\SoFinderException;
use SohoPHP\SoFinder\Value\Entry;
use SohoPHP\SoFinder\Value\ResourceType;

/** Shared entry URL policy with a host-owned named-route callback. */
final class RoutingEntryUrlGenerator implements EntryUrlGeneratorInterface
{
    private readonly \Closure $routes;

    /**
     * @param callable(string,array<string,string|int|float|bool|null>,bool):string $routes
     * @param iterable<EntryUrlContextProviderInterface> $contextProviders
     */
    public function __construct(callable $routes, private readonly iterable $contextProviders = [])
    {
        $this->routes = \Closure::fromCallable($routes);
    }

    public function generate(ResourceType $resource, Entry $entry): ?string
    {
        if ($entry->directory) {
            return null;
        }
        if ($resource->entryUrlRoute !== '') {
            return ($this->routes)($resource->entryUrlRoute, $this->parameters($resource, $entry), $resource->entryUrlAbsolute);
        }
        if ($resource->deliveryMode === 'public') {
            return $entry->url;
        }

        return ($this->routes)('sofinder_api_content', [
            'resource' => $resource->name,
            'path' => $entry->path,
            'disposition' => 'inline',
        ], false);
    }

    /** @return array<string,string|int|float|bool> */
    private function parameters(ResourceType $resource, Entry $entry): array
    {
        $context = [
            'resource' => $resource->name,
            'path' => $entry->path,
            'name' => $entry->name,
            'stem' => (string) pathinfo($entry->name, PATHINFO_FILENAME),
            'extension' => strtolower((string) pathinfo($entry->name, PATHINFO_EXTENSION)),
            'storage_url' => $entry->url,
        ];
        foreach ($this->contextProviders as $provider) {
            $context = array_replace($context, $provider->context($resource, $entry));
        }

        $parameters = [];
        foreach ($resource->entryUrlParameters as $name => $template) {
            $parameters[$name] = $this->render($template, $context);
        }

        return $parameters;
    }

    /** @param array<string,string|int|float|bool|null> $context */
    private function render(string $template, array $context): string|int|float|bool
    {
        if (preg_match('/^\{([a-zA-Z][a-zA-Z0-9_]*)\}$/', $template, $matches) === 1) {
            return $this->value($matches[1], $context);
        }

        return (string) preg_replace_callback(
            '/\{([a-zA-Z][a-zA-Z0-9_]*)\}/',
            fn (array $matches): string => (string) $this->value($matches[1], $context),
            $template,
        );
    }

    /** @param array<string,string|int|float|bool|null> $context */
    private function value(string $name, array $context): string|int|float|bool
    {
        $value = $context[$name] ?? null;
        if (!is_string($value) && !is_int($value) && !is_float($value) && !is_bool($value)) {
            throw new SoFinderException(sprintf('Entry URL context value "%s" is unavailable.', $name), 'entry_url_context_missing', 500);
        }

        return $value;
    }
}

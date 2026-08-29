<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Storage;

use SohoPHP\SoFinder\Contract\StorageAdapterFactoryInterface;
use SohoPHP\SoFinder\Exception\SoFinderException;
use SohoPHP\SoFinder\ResourceRegistry;
use SohoPHP\SoFinder\Security\PathGuard;
use SohoPHP\SoFinder\Value\ResourceStorage;
use SohoPHP\SoFinder\Value\ResourceType;

/**
 * Builds the framework-independent resource registry from normalized arrays.
 *
 * Framework bridges may supply a public URL resolver for mount paths. Keeping
 * that concern outside this class lets every bridge share adapter lookup,
 * defaults and security-related resource construction.
 */
final class ResourceRegistryFactory
{
    /** @var array<string, StorageAdapterFactoryInterface> */
    private array $factories = [];

    private readonly \Closure $publicUrlResolver;

    /**
     * @param iterable<StorageAdapterFactoryInterface> $factories
     * @param null|callable(string):string $publicUrlResolver
     */
    public function __construct(
        private readonly PathGuard $pathGuard = new PathGuard(),
        iterable $factories = [],
        ?callable $publicUrlResolver = null,
    ) {
        $factories = is_array($factories) && $factories === []
            ? [new LocalStorageAdapterFactory($pathGuard)]
            : $factories;

        foreach ($factories as $factory) {
            $alias = strtolower(trim($factory->alias()));
            if ($alias === '' || isset($this->factories[$alias])) {
                throw new \InvalidArgumentException(sprintf('The SoFinder storage adapter alias "%s" is empty or duplicated.', $alias));
            }
            $this->factories[$alias] = $factory;
        }

        $this->publicUrlResolver = $publicUrlResolver === null
            ? static fn (string $publicUrl): string => $publicUrl
            : \Closure::fromCallable($publicUrlResolver);
    }

    /** @param array<string, array<string, mixed>> $configuration */
    public function create(array $configuration): ResourceRegistry
    {
        $items = [];
        foreach ($configuration as $name => $values) {
            $publicUrl = ($this->publicUrlResolver)((string) ($values['public_url'] ?? ''));
            $resource = new ResourceType(
                (string) $name,
                (string) ($values['root'] ?? ''),
                $publicUrl,
                array_values((array) ($values['allowed_extensions'] ?? [])),
                array_values((array) ($values['denied_extensions'] ?? [])),
                array_values((array) ($values['allowed_mime_types'] ?? [])),
                (int) ($values['max_size'] ?? 20_971_520),
                (bool) ($values['read_only'] ?? false),
                (int) ($values['quota'] ?? 0),
                array_values((array) ($values['roles'] ?? [])),
                array_map(static fn (mixed $roles): array => array_values((array) $roles), (array) ($values['operation_roles'] ?? [])),
                (int) ($values['max_file_name_length'] ?? 120),
                (int) ($values['max_folder_name_length'] ?? 50),
                (int) ($values['max_folder_depth'] ?? 5),
                (int) ($values['max_image_pixels'] ?? 50_000_000),
                array_values(array_map(fn (mixed $rule): array => [
                    'path' => $this->pathGuard->normalize((string) ((array) $rule)['path']),
                    'operations' => array_values(array_map('strval', (array) ((array) $rule)['operations'])),
                    'roles' => array_values(array_map('strval', (array) (((array) $rule)['roles'] ?? []))),
                    'allow' => (bool) (((array) $rule)['allow'] ?? true),
                ], (array) ($values['path_acl'] ?? []))),
                (string) ($values['delivery_mode'] ?? 'public'),
                (int) ($values['max_batch_items'] ?? 100),
                (int) ($values['max_recursive_items'] ?? 10_000),
                (int) ($values['max_archive_items'] ?? 1_000),
                (int) ($values['max_archive_bytes'] ?? 536_870_912),
                (int) ($values['max_image_width'] ?? 12_000),
                (int) ($values['max_image_height'] ?? 12_000),
                (string) ($values['animated_image_policy'] ?? 'preserve'),
                (string) ($values['entry_url']['route'] ?? ''),
                array_map('strval', (array) ($values['entry_url']['parameters'] ?? [])),
                (bool) ($values['entry_url']['absolute'] ?? false),
            );
            $adapter = strtolower((string) ($values['adapter'] ?? 'local'));
            $factory = $this->factories[$adapter] ?? null;
            if ($factory === null) {
                throw new SoFinderException(sprintf('Storage adapter "%s" is not registered.', $adapter), 'unknown_storage_adapter', 500);
            }
            $options = $values['options'] ?? [];
            if (!is_array($options)) {
                throw new SoFinderException(sprintf('Storage adapter options for "%s" must be an object.', $name), 'invalid_storage_options', 500);
            }
            $items[] = new ResourceStorage($resource, $factory->create($resource, $options));
        }

        return new ResourceRegistry($items);
    }
}

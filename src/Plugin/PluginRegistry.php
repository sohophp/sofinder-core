<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Plugin;

use SohoPHP\SoFinder\Contract\PluginInterface;

final readonly class PluginRegistry
{
    /** @var list<array<string,mixed>> */
    private array $descriptors;

    /** @param iterable<PluginInterface> $plugins */
    public function __construct(iterable $plugins)
    {
        $descriptors = [];
        $names = [];
        foreach ($plugins as $plugin) {
            $descriptor = $this->normalize($plugin->descriptor());
            if (isset($names[$descriptor['name']])) {
                throw new \InvalidArgumentException(sprintf('SoFinder plugin name "%s" is registered more than once.', $descriptor['name']));
            }
            $names[$descriptor['name']] = true;
            $descriptors[] = $descriptor;
        }

        usort($descriptors, static fn (array $left, array $right): int => $left['name'] <=> $right['name']);
        $this->descriptors = $descriptors;
    }

    /** @return list<array<string,mixed>> */
    public function descriptors(): array
    {
        return $this->descriptors;
    }

    /**
     * @param array<string,mixed> $descriptor
     * @return array<string,mixed>
     */
    private function normalize(array $descriptor): array
    {
        $allowedFields = ['descriptorVersion', 'name', 'version', 'capabilities', 'resourceTypes', 'requiredOperations', 'configurationKeys', 'uiActions', 'previewers', 'extensions'];
        $unknown = array_values(array_diff(array_keys($descriptor), $allowedFields));
        if ($unknown !== []) throw new \InvalidArgumentException(sprintf('SoFinder plugin descriptor contains unknown field "%s".', $unknown[0]));
        $descriptorVersion = $descriptor['descriptorVersion'] ?? '1.0';
        if ($descriptorVersion !== '1.0') throw new \InvalidArgumentException('SoFinder plugin descriptorVersion must be 1.0.');
        $name = $descriptor['name'] ?? null;
        $version = $descriptor['version'] ?? null;
        $capabilities = $descriptor['capabilities'] ?? null;
        if (!is_string($name) || preg_match('/^[a-z][a-z0-9._-]{1,63}$/', $name) !== 1) {
            throw new \InvalidArgumentException('SoFinder plugin names must contain 2-64 lowercase letters, numbers, dots, underscores or hyphens.');
        }
        if (!is_string($version) || $version === '' || strlen($version) > 64 || preg_match('/^[0-9A-Za-z][0-9A-Za-z.+_-]*$/', $version) !== 1) {
            throw new \InvalidArgumentException(sprintf('SoFinder plugin "%s" has an invalid version.', $name));
        }
        if (!is_array($capabilities)) {
            throw new \InvalidArgumentException(sprintf('SoFinder plugin "%s" capabilities must be an array.', $name));
        }

        $normalized = [];
        foreach ($capabilities as $capability) {
            if (!is_string($capability) || preg_match('/^[a-z][a-z0-9._-]{0,63}$/', $capability) !== 1) {
                throw new \InvalidArgumentException(sprintf('SoFinder plugin "%s" has an invalid capability.', $name));
            }
            $normalized[$capability] = true;
        }

        $result = ['descriptorVersion' => '1.0', 'name' => $name, 'version' => $version, 'capabilities' => array_keys($normalized)];
        if (array_key_exists('resourceTypes', $descriptor)) {
            $result['resourceTypes'] = $this->descriptorList($name, 'resourceTypes', $descriptor['resourceTypes'], '/^(?:any|file|image|directory)$/D');
        }
        if (array_key_exists('requiredOperations', $descriptor)) {
            $result['requiredOperations'] = $this->descriptorList($name, 'requiredOperations', $descriptor['requiredOperations'], '/^[a-z][a-z0-9_-]{0,31}$/D');
        }
        if (array_key_exists('configurationKeys', $descriptor)) {
            $result['configurationKeys'] = $this->descriptorList($name, 'configurationKeys', $descriptor['configurationKeys'], '/^[a-z][a-z0-9_.-]{0,63}$/D');
        }
        if (array_key_exists('uiActions', $descriptor)) {
            $result['uiActions'] = $this->normalizeUiActions($name, $descriptor['uiActions']);
        }
        if (array_key_exists('previewers', $descriptor)) {
            $result['previewers'] = $this->normalizePreviewers($name, $descriptor['previewers']);
        }
        if (array_key_exists('extensions', $descriptor)) {
            if (!is_array($descriptor['extensions'])) throw new \InvalidArgumentException(sprintf('SoFinder plugin "%s" extensions must be an object.', $name));
            $result['extensions'] = $descriptor['extensions'];
        }

        return $result;
    }

    /** @return list<array<string,mixed>> */
    private function normalizeUiActions(string $plugin, mixed $actions): array
    {
        if (!is_array($actions) || count($actions) > 20) {
            throw new \InvalidArgumentException(sprintf('SoFinder plugin "%s" uiActions must be an array of at most 20 actions.', $plugin));
        }
        $result = [];
        $ids = [];
        foreach ($actions as $action) {
            if (!is_array($action)) {
                throw new \InvalidArgumentException(sprintf('SoFinder plugin "%s" contains an invalid UI action.', $plugin));
            }
            $this->assertKnownFields($plugin, 'UI action', $action, ['id', 'label', 'slot', 'url', 'selection', 'requires']);
            $id = $action['id'] ?? null;
            $labels = $action['label'] ?? null;
            $slot = $action['slot'] ?? null;
            $url = $action['url'] ?? null;
            $selection = $action['selection'] ?? 'any';
            $requires = $action['requires'] ?? 'read';
            if (!is_string($id) || preg_match('/^[a-z][a-z0-9_-]{1,31}$/D', $id) !== 1 || isset($ids[$id])) {
                throw new \InvalidArgumentException(sprintf('SoFinder plugin "%s" UI action IDs must be unique safe identifiers.', $plugin));
            }
            if (!is_array($labels) || !isset($labels['en']) || !is_string($labels['en'])) {
                throw new \InvalidArgumentException(sprintf('SoFinder plugin "%s" UI actions require an English label.', $plugin));
            }
            $this->assertKnownFields($plugin, 'UI action label', $labels, ['en', 'zh-cn', 'zh-tw']);
            $normalizedLabels = [];
            foreach (['en', 'zh-cn', 'zh-tw'] as $locale) {
                if (!isset($labels[$locale])) continue;
                if (!is_string($labels[$locale]) || trim($labels[$locale]) === '' || mb_strlen($labels[$locale]) > 48 || preg_match('/[\x00-\x1F\x7F]/u', $labels[$locale]) === 1) {
                    throw new \InvalidArgumentException(sprintf('SoFinder plugin "%s" contains an invalid UI action label.', $plugin));
                }
                $normalizedLabels[$locale] = trim($labels[$locale]);
            }
            if (!is_string($slot) || !in_array($slot, ['utility', 'toolbar', 'context', 'details'], true)
                || !is_string($selection) || !in_array($selection, ['none', 'any', 'file', 'image'], true)
                || !is_string($requires) || preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $requires) !== 1
                || !$this->safePath($url)) {
                throw new \InvalidArgumentException(sprintf('SoFinder plugin "%s" contains an unsafe UI action.', $plugin));
            }
            $ids[$id] = true;
            $result[] = ['id' => $id, 'label' => $normalizedLabels, 'slot' => $slot, 'url' => $url, 'selection' => $selection, 'requires' => $requires];
        }

        return $result;
    }

    /** @return list<array{id:string,mimeTypes:list<string>,extensions:list<string>,url:string}> */
    private function normalizePreviewers(string $plugin, mixed $previewers): array
    {
        if (!is_array($previewers) || count($previewers) > 20) {
            throw new \InvalidArgumentException(sprintf('SoFinder plugin "%s" previewers must be an array of at most 20 entries.', $plugin));
        }
        $result = [];
        $ids = [];
        foreach ($previewers as $previewer) {
            if (!is_array($previewer)) throw new \InvalidArgumentException(sprintf('SoFinder plugin "%s" contains an invalid previewer.', $plugin));
            $this->assertKnownFields($plugin, 'previewer', $previewer, ['id', 'url', 'mimeTypes', 'extensions']);
            $id = $previewer['id'] ?? null;
            $url = $previewer['url'] ?? null;
            $mimeTypes = $this->safeStringList($previewer['mimeTypes'] ?? [], '#^[a-z0-9.+_-]+/[a-z0-9.+*_-]+$#D', 32);
            $extensions = $this->safeStringList($previewer['extensions'] ?? [], '/^[a-z0-9][a-z0-9.+_-]{0,15}$/D', 32);
            if (!is_string($id) || preg_match('/^[a-z][a-z0-9_-]{1,31}$/D', $id) !== 1 || isset($ids[$id])
                || !$this->safePath($url)
                || ($mimeTypes === [] && $extensions === [])) {
                throw new \InvalidArgumentException(sprintf('SoFinder plugin "%s" contains an unsafe previewer.', $plugin));
            }
            $ids[$id] = true;
            $result[] = ['id' => $id, 'mimeTypes' => $mimeTypes, 'extensions' => $extensions, 'url' => $url];
        }

        return $result;
    }

    /** @return list<string> */
    private function safeStringList(mixed $values, string $pattern, int $maximum): array
    {
        if (!is_array($values) || count($values) > $maximum) return [];
        $result = [];
        foreach ($values as $value) {
            if (!is_string($value) || preg_match($pattern, strtolower($value)) !== 1) return [];
            $result[strtolower($value)] = true;
        }

        return array_keys($result);
    }

    /** @return list<string> */
    private function descriptorList(string $plugin, string $field, mixed $values, string $pattern): array
    {
        if (!is_array($values) || count($values) > 64) {
            throw new \InvalidArgumentException(sprintf('SoFinder plugin "%s" %s must be an array of at most 64 values.', $plugin, $field));
        }
        $result = $this->safeStringList($values, $pattern, 64);
        if ($values !== [] && $result === []) {
            throw new \InvalidArgumentException(sprintf('SoFinder plugin "%s" has an invalid %s declaration.', $plugin, $field));
        }

        return $result;
    }

    private function safePath(mixed $url): bool
    {
        if (!is_string($url) || !str_starts_with($url, '/') || str_starts_with($url, '//') || strlen($url) > 512 || preg_match('/[\x00-\x20\x7F]/', $url) === 1) return false;
        $path = rawurldecode((string) parse_url($url, PHP_URL_PATH));
        return array_filter(explode('/', $path), static fn (string $segment): bool => $segment === '..') === [];
    }

    /**
     * @param array<string,mixed> $value
     * @param list<string> $allowed
     */
    private function assertKnownFields(string $plugin, string $section, array $value, array $allowed): void
    {
        $unknown = array_values(array_diff(array_keys($value), $allowed));
        if ($unknown !== []) throw new \InvalidArgumentException(sprintf('SoFinder plugin "%s" %s contains unknown field "%s".', $plugin, $section, $unknown[0]));
    }
}

<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Configuration;

/**
 * Framework-neutral normalization for YAML, PHP and adapter-provided arrays.
 *
 * Framework bridges may perform additional host-specific validation first, but
 * must pass the resolved configuration through this class before wiring the
 * shared application services.
 */
final class ConfigurationNormalizer
{
    /** @var array<string, mixed> */
    public const DEFAULTS = [
        'route_prefix' => '/admin/sofinder',
        'cache_dir' => '%kernel.cache_dir%/sofinder',
        'metadata_file' => '%kernel.project_dir%/var/sofinder/metadata.json',
        'quarantine_dir' => '%kernel.cache_dir%/sofinder/quarantine',
        'chunk_dir' => '%kernel.cache_dir%/sofinder/chunks',
        'usage_dir' => '%kernel.project_dir%/var/sofinder/usage',
        'trash_dir' => '%kernel.project_dir%/var/sofinder/trash',
        'cluster' => ['state_service' => null, 'chunk_upload_store_service' => null, 'shared_preview_cache' => false],
        'signed_urls' => ['enabled' => false, 'secret' => '%kernel.secret%', 'default_ttl_seconds' => 300, 'max_ttl_seconds' => 3600],
        'picker' => ['allowed_origins' => []],
        'asset_catalog' => ['enabled' => false, 'store_service' => null, 'register_existing' => 'lazy', 'alt_locales' => ['en', 'zh-cn', 'zh-tw']],
        'asset_search' => ['enabled' => true, 'provider_service' => null, 'max_scanned_entries' => 10000],
        'asset_usage' => ['enabled' => false, 'store_service' => null],
        'asset_access_sessions' => ['enabled' => false, 'store_service' => null, 'default_ttl_seconds' => 3600, 'max_ttl_seconds' => 86400, 'max_assets' => 50],
        'workspaces' => ['enabled' => false, 'default' => 'main', 'resolver_service' => null, 'option_provider_service' => null],
        'filesystem_permissions' => ['directory_mode' => '0775', 'file_mode' => '0664'],
        'chunk_size' => 5242880,
        'max_upload_chunks' => 200,
        'uploads' => ['naming' => ['lowercase_extensions' => true]],
        'trash_retention_days' => 30,
        'trash_max_items' => 1000,
        'trash_max_bytes' => 1073741824,
        'ckeditor4' => ['overwrite_on_upload' => false],
        'malware_scanning' => ['enabled' => false, 'endpoint' => 'tcp://127.0.0.1:3310', 'timeout_seconds' => 5.0, 'history_limit' => 100, 'status_roles' => ['ROLE_ADMIN']],
        'image_processing' => [
            'driver' => 'auto', 'max_width' => 12000, 'max_height' => 12000,
            'max_single_frame_pixels' => 50000000, 'max_frames' => 200,
            'max_total_pixels' => 100000000, 'memory_bytes' => 268435456,
            'map_bytes' => 536870912, 'disk_bytes' => 1073741824, 'threads' => 1,
            'timeout_seconds' => 30, 'watermark_font' => null,
            'watermark_font_auto_download' => true,
        ],
        'image_variants' => [
            'enabled' => false, 'widths' => [320, 640, 960, 1280, 1920],
            'formats' => ['original', 'webp'], 'quality' => 82, 'mode' => 'on_demand',
            'max_variants_per_asset' => 10, 'cache_ttl_seconds' => 2592000,
        ],
        'document_preview' => [
            'mode' => 'auto', 'pdf' => true, 'office' => false,
            'office_binary' => '/usr/bin/libreoffice', 'timeout_seconds' => 30,
            'max_bytes' => 52428800, 'job_ttl_seconds' => 86400,
            'cache_ttl_seconds' => 604800,
        ],
        'ui' => [
            'mode' => 'auto', 'header' => true, 'logo' => true, 'search' => true,
            'language_switcher' => true, 'view_switcher' => true,
            'folder_tree' => false, 'scale' => 'standard',
            'upload_conflict_strategy' => 'ask', 'lowercase_upload_extensions' => null,
        ],
        'features' => [
            'folder_tree' => true, 'recent' => true, 'favorites' => true,
            'quick_access' => true, 'quick_access_files' => false, 'tags' => true,
            'archive' => true, 'trash' => true, 'batch_rename' => true,
            'image_editing' => true, 'image_processing' => true,
            'document_preview' => true, 'security_status' => true,
            'folder_upload' => true, 'text_preview' => true, 'checksum' => true,
            'qr_code' => true,
        ],
        'maintenance' => ['mode' => 'inline', 'min_interval_seconds' => 300, 'max_items_per_run' => 50],
        'limits' => [
            'normal' => ['max_requests' => 240, 'interval' => 60, 'max_concurrent' => 12],
            'upload' => ['max_requests' => 30, 'interval' => 60, 'max_concurrent' => 3],
            'image' => ['max_requests' => 60, 'interval' => 60, 'max_concurrent' => 2],
            'thumbnail' => ['max_requests' => 600, 'interval' => 60, 'max_concurrent' => 16],
            'archive' => ['max_requests' => 10, 'interval' => 60, 'max_concurrent' => 1],
            'transfer' => ['max_requests' => 60, 'interval' => 60, 'max_concurrent' => 2],
        ],
        'image_presets' => [],
        'theme' => [
            'accent' => '#276ef1', 'background' => '#f4f6f9', 'panel' => '#ffffff',
            'text' => '#1c2735', 'muted' => '#667282', 'danger' => '#c13a43',
            'radius' => '10px',
        ],
        'resources' => [],
    ];

    /** @var array<string, mixed> */
    private const RESOURCE_DEFAULTS = [
        'adapter' => 'local', 'options' => [], 'public_url' => '',
        'delivery_mode' => 'public',
        'entry_url' => ['route' => '', 'parameters' => [], 'absolute' => false],
        'allowed_extensions' => [],
        'denied_extensions' => ['php', 'phtml', 'phar', 'cgi', 'pl', 'exe', 'sh', 'html', 'htm', 'js'],
        'allowed_mime_types' => [], 'max_size' => 20971520, 'read_only' => false,
        'quota' => 0, 'max_file_name_length' => 120, 'max_folder_name_length' => 50,
        'max_folder_depth' => 5, 'max_image_pixels' => 50000000,
        'max_batch_items' => 100, 'max_recursive_items' => 10000,
        'max_archive_items' => 1000, 'max_archive_bytes' => 536870912,
        'max_image_width' => 12000, 'max_image_height' => 12000,
        'animated_image_policy' => 'preserve', 'roles' => [], 'operation_roles' => [],
        'path_acl' => [],
    ];

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $hostDefaults Framework-specific paths/secrets.
     * @return array<string, mixed>
     */
    public function normalize(array $config, array $hostDefaults = []): array
    {
        $this->rejectUnknownKeys($hostDefaults, self::DEFAULTS);
        $this->rejectUnknownKeys($config, self::DEFAULTS);
        $normalized = $this->merge(self::DEFAULTS, $hostDefaults);
        $normalized = $this->merge($normalized, $config);

        foreach ($normalized['resources'] as $name => $resource) {
            if (!is_string($name) || trim($name) === '' || !is_array($resource)) {
                throw new \InvalidArgumentException('SoFinder resources must use non-empty names mapped to configuration arrays.');
            }
            $normalized['resources'][$name] = $this->merge(self::RESOURCE_DEFAULTS, $resource);
        }
        foreach ($normalized['image_presets'] as $name => $preset) {
            if (!is_string($name) || trim($name) === '' || !is_array($preset)) {
                throw new \InvalidArgumentException('SoFinder image_presets must use non-empty names mapped to configuration arrays.');
            }
            $normalized['image_presets'][$name] = $this->merge(['quality' => 88], $preset);
        }

        $legacyLowercase = $normalized['ui']['lowercase_upload_extensions'];
        if ($legacyLowercase !== null) {
            if (!is_bool($legacyLowercase)) {
                throw new \InvalidArgumentException('SoFinder ui.lowercase_upload_extensions must be boolean or null.');
            }
            $normalized['uploads']['naming']['lowercase_extensions'] = $legacyLowercase;
        }
        $normalized['ui']['lowercase_upload_extensions'] = $normalized['uploads']['naming']['lowercase_extensions'];

        $timeout = $normalized['malware_scanning']['timeout_seconds'];
        if (is_int($timeout) || is_float($timeout)) {
            $normalized['malware_scanning']['timeout_seconds'] = (float) $timeout;
        }

        $this->validate($normalized);

        return $normalized;
    }

    /**
     * Normalizes an array already resolved by a host configuration system.
     *
     * @param array<string, mixed> $resolved
     * @param array<int, array<string, mixed>> $sources
     * @return array<string, mixed>
     */
    public function normalizeResolved(array $resolved, array $sources = []): array
    {
        $legacyLowercase = null;
        foreach ($sources as $source) {
            $candidate = $source['ui']['lowercase_upload_extensions'] ?? null;
            if ($candidate !== null) {
                $legacyLowercase = $candidate;
            }
        }
        if ($legacyLowercase !== null) {
            $resolved['uploads']['naming']['lowercase_extensions'] = $legacyLowercase;
        }
        $resolved['ui']['lowercase_upload_extensions'] = $resolved['uploads']['naming']['lowercase_extensions'];

        return $resolved;
    }

    /** @param array<string, mixed> $config */
    private function validate(array $config): void
    {
        if (!is_string($config['route_prefix']) || $config['route_prefix'] === '' || $config['route_prefix'][0] !== '/') {
            throw new \InvalidArgumentException('SoFinder route_prefix must be a non-empty absolute URL path.');
        }
        if ($config['resources'] === []) {
            throw new \InvalidArgumentException('SoFinder resources must contain at least one resource.');
        }

        $this->enum($config, 'image_processing.driver', ['auto', 'gd', 'imagick']);
        $this->enum($config, 'document_preview.mode', ['auto', 'inline', 'messenger']);
        $this->enum($config, 'ui.mode', ['auto', 'manager', 'picker']);
        $this->enum($config, 'ui.scale', ['compact', 'standard', 'large', 'xlarge']);
        $this->enum($config, 'ui.upload_conflict_strategy', ['ask', 'rename', 'overwrite', 'skip']);
        $this->enum($config, 'maintenance.mode', ['inline', 'messenger', 'external', 'disabled']);

        foreach ([
            'cluster.shared_preview_cache', 'signed_urls.enabled', 'asset_catalog.enabled',
            'asset_search.enabled', 'asset_usage.enabled', 'asset_access_sessions.enabled',
            'workspaces.enabled', 'uploads.naming.lowercase_extensions',
            'ckeditor4.overwrite_on_upload', 'image_processing.watermark_font_auto_download',
            'image_variants.enabled', 'document_preview.pdf', 'document_preview.office',
            'ui.header', 'ui.logo', 'ui.search', 'ui.language_switcher',
            'ui.view_switcher', 'ui.folder_tree',
        ] as $path) {
            $this->boolean($config, $path);
        }
        foreach (array_keys($config['features']) as $key) {
            $this->boolean($config, 'features.' . $key);
        }

        $this->integerRange($config, 'chunk_size', 262144, 16777216);
        $this->integerRange($config, 'max_upload_chunks', 1, 1000);
        $this->integerRange($config, 'trash_retention_days', 1, 3650);
        $this->integerRange($config, 'trash_max_items', 1, 100000);
        $this->integerRange($config, 'trash_max_bytes', 1, PHP_INT_MAX);
        $this->integerRange($config, 'signed_urls.default_ttl_seconds', 30, 86400);
        $this->integerRange($config, 'signed_urls.max_ttl_seconds', 30, 86400);
        $this->integerRange($config, 'asset_search.max_scanned_entries', 100, 100000);
        $this->integerRange($config, 'asset_access_sessions.default_ttl_seconds', 60, 86400);
        $this->integerRange($config, 'asset_access_sessions.max_ttl_seconds', 60, 604800);
        $this->integerRange($config, 'asset_access_sessions.max_assets', 1, 500);
        $this->integerRange($config, 'image_processing.max_width', 1, 100000);
        $this->integerRange($config, 'image_processing.max_height', 1, 100000);
        $this->integerRange($config, 'image_processing.max_single_frame_pixels', 1, PHP_INT_MAX);
        $this->integerRange($config, 'image_processing.max_frames', 1, 10000);
        $this->integerRange($config, 'image_processing.max_total_pixels', 1, PHP_INT_MAX);
        $this->integerRange($config, 'image_processing.memory_bytes', 16777216, PHP_INT_MAX);
        $this->integerRange($config, 'image_processing.map_bytes', 16777216, PHP_INT_MAX);
        $this->integerRange($config, 'image_processing.disk_bytes', 16777216, PHP_INT_MAX);
        $this->integerRange($config, 'image_processing.threads', 1, 16);
        $this->integerRange($config, 'image_processing.timeout_seconds', 1, 300);
        $this->integerRange($config, 'image_variants.quality', 1, 100);
        $this->integerRange($config, 'image_variants.max_variants_per_asset', 1, 20);
        $this->integerRange($config, 'image_variants.cache_ttl_seconds', 60, 31536000);
        $this->integerRange($config, 'document_preview.timeout_seconds', 1, 300);
        $this->integerRange($config, 'document_preview.max_bytes', 1, PHP_INT_MAX);
        $this->integerRange($config, 'document_preview.job_ttl_seconds', 60, 604800);
        $this->integerRange($config, 'document_preview.cache_ttl_seconds', 60, 31536000);
        $this->integerRange($config, 'maintenance.min_interval_seconds', 0, 86400);
        $this->integerRange($config, 'maintenance.max_items_per_run', 1, 10000);
        if ($config['signed_urls']['default_ttl_seconds'] > $config['signed_urls']['max_ttl_seconds']) {
            throw new \InvalidArgumentException('SoFinder signed_urls.default_ttl_seconds cannot exceed max_ttl_seconds.');
        }
        if ($config['asset_access_sessions']['default_ttl_seconds'] > $config['asset_access_sessions']['max_ttl_seconds']) {
            throw new \InvalidArgumentException('SoFinder asset_access_sessions.default_ttl_seconds cannot exceed max_ttl_seconds.');
        }

        foreach ($config['picker']['allowed_origins'] as $origin) {
            if (!is_string($origin) || preg_match('#^https?://[A-Za-z0-9.-]+(?::[1-9][0-9]{0,4})?$#D', $origin) !== 1) {
                throw new \InvalidArgumentException('Every SoFinder picker.allowed_origins value must be an exact HTTP(S) origin without a path.');
            }
        }
        $locales = $config['asset_catalog']['alt_locales'];
        if (!is_array($locales) || $locales === [] || count($locales) > 20 || count(array_unique($locales)) !== count($locales)) {
            throw new \InvalidArgumentException('SoFinder asset_catalog.alt_locales must contain 1 to 20 unique language tags.');
        }
        foreach ($locales as $locale) {
            if (!is_string($locale) || preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})*$/D', $locale) !== 1) {
                throw new \InvalidArgumentException('Every SoFinder asset_catalog.alt_locales value must be a lowercase BCP 47 language tag.');
            }
        }
        foreach ($config['image_variants']['widths'] as $width) {
            if (!is_int($width) || $width < 32 || $width > 8192) {
                throw new \InvalidArgumentException('Every SoFinder image_variants.widths value must be between 32 and 8192.');
            }
        }
        foreach ($config['image_variants']['formats'] as $format) {
            if (!is_string($format) || !in_array($format, ['original', 'webp', 'avif'], true)) {
                throw new \InvalidArgumentException('SoFinder image_variants.formats contains an invalid value.');
            }
        }
        foreach ($config['limits'] as $group => $_limit) {
            $this->integerRange($config, "limits.$group.max_requests", 0, PHP_INT_MAX);
            $this->integerRange($config, "limits.$group.interval", 1, PHP_INT_MAX);
            $this->integerRange($config, "limits.$group.max_concurrent", 0, PHP_INT_MAX);
        }
        foreach ($config['image_presets'] as $name => $_preset) {
            $this->integerRange($config, "image_presets.$name.width", 1, 4096);
            $this->integerRange($config, "image_presets.$name.height", 1, 4096);
            $this->integerRange($config, "image_presets.$name.quality", 1, 100);
        }
        if (!is_float($config['malware_scanning']['timeout_seconds']) || $config['malware_scanning']['timeout_seconds'] < 0.1 || $config['malware_scanning']['timeout_seconds'] > 60.0) {
            throw new \InvalidArgumentException('SoFinder malware_scanning.timeout_seconds must be between 0.1 and 60.');
        }
        $this->integerRange($config, 'malware_scanning.history_limit', 1, 1000);
        if (!is_string($config['malware_scanning']['endpoint']) || preg_match('#^(?:tcp://[A-Za-z0-9.:-]+|unix:///[^ -]+)$#D', $config['malware_scanning']['endpoint']) !== 1) {
            throw new \InvalidArgumentException('SoFinder malware_scanning.endpoint must be a tcp:// host or absolute unix:/// socket.');
        }
        foreach (['directory_mode' => '/^0?[0-3]?[0-7]{3}$/D', 'file_mode' => '/^0?[0-7]{3}$/D'] as $key => $pattern) {
            if (!is_string($config['filesystem_permissions'][$key]) || preg_match($pattern, $config['filesystem_permissions'][$key]) !== 1) {
                throw new \InvalidArgumentException("SoFinder filesystem_permissions.$key must be a quoted octal mode.");
            }
        }
        foreach ($config['resources'] as $name => $resource) {
            if (!is_string($resource['root'] ?? null) || trim($resource['root']) === '') {
                throw new \InvalidArgumentException("SoFinder resource $name requires a non-empty root.");
            }
            if (!in_array($resource['delivery_mode'], ['public', 'proxy'], true)) {
                throw new \InvalidArgumentException("SoFinder resource $name has an invalid delivery_mode.");
            }
            if (!in_array($resource['animated_image_policy'], ['preserve', 'reject'], true)) {
                throw new \InvalidArgumentException("SoFinder resource $name has an invalid animated_image_policy.");
            }
            foreach ([
                'max_size' => PHP_INT_MAX, 'max_file_name_length' => 255,
                'max_folder_name_length' => 255, 'max_folder_depth' => 100,
                'max_image_pixels' => PHP_INT_MAX, 'max_batch_items' => 1000,
                'max_recursive_items' => 100000, 'max_archive_items' => 100000,
                'max_archive_bytes' => PHP_INT_MAX, 'max_image_width' => 100000,
                'max_image_height' => 100000,
            ] as $key => $maximum) {
                if (!is_int($resource[$key]) || $resource[$key] < 1 || $resource[$key] > $maximum) {
                    throw new \InvalidArgumentException("SoFinder resource $name.$key must be between 1 and $maximum.");
                }
            }
            if (!is_int($resource['quota']) || $resource['quota'] < 0) {
                throw new \InvalidArgumentException("SoFinder resource $name.quota must be a non-negative integer.");
            }
            if (!is_bool($resource['read_only']) || !is_bool($resource['entry_url']['absolute'])) {
                throw new \InvalidArgumentException("SoFinder resource $name boolean options must be boolean values.");
            }
            foreach ($resource['path_acl'] as $index => $rule) {
                if (!is_array($rule) || !isset($rule['operations']) || !is_array($rule['operations']) || $rule['operations'] === []) {
                    throw new \InvalidArgumentException("SoFinder resource $name.path_acl.$index requires operations.");
                }
            }
        }
    }

    /** @param array<string, mixed> $config */
    private function boolean(array $config, string $path): void
    {
        if (!is_bool($this->value($config, $path))) {
            throw new \InvalidArgumentException("SoFinder $path must be boolean.");
        }
    }

    /**
     * @param array<string, mixed> $config
     * @param list<string> $allowed
     */
    private function enum(array $config, string $path, array $allowed): void
    {
        $value = $this->value($config, $path);
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new \InvalidArgumentException("SoFinder $path has an invalid value.");
        }
    }

    /** @param array<string, mixed> $config */
    private function integerRange(array $config, string $path, int $minimum, int $maximum): void
    {
        $value = $this->value($config, $path);
        if (!is_int($value) || $value < $minimum || $value > $maximum) {
            throw new \InvalidArgumentException("SoFinder $path must be between $minimum and $maximum.");
        }
    }

    /** @param array<string, mixed> $config */
    private function value(array $config, string $path): mixed
    {
        $value = $config;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                throw new \InvalidArgumentException("Missing SoFinder configuration key $path.");
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $schema
     */
    private function rejectUnknownKeys(array $input, array $schema, string $path = ''): void
    {
        foreach ($input as $key => $value) {
            if (!is_string($key) || !array_key_exists($key, $schema)) {
                throw new \InvalidArgumentException('Unknown SoFinder configuration key ' . ltrim($path . '.' . (string) $key, '.') . '.');
            }
            $current = ltrim($path . '.' . $key, '.');
            if ($current === 'resources') {
                if (!is_array($value)) {
                    throw new \InvalidArgumentException('SoFinder resources must be a configuration array.');
                }
                foreach ($value as $resource) {
                    if (is_array($resource)) {
                        $this->rejectUnknownKeys($resource, ['root' => null] + self::RESOURCE_DEFAULTS, 'resources.*');
                    }
                }
                continue;
            }
            if ($current === 'image_presets') {
                if (!is_array($value)) {
                    throw new \InvalidArgumentException('SoFinder image_presets must be a configuration array.');
                }
                foreach ($value as $preset) {
                    if (is_array($preset)) {
                        $this->rejectUnknownKeys($preset, ['width' => null, 'height' => null, 'quality' => 88], 'image_presets.*');
                    }
                }
                continue;
            }
            if (in_array($current, ['resources.*.options', 'resources.*.entry_url.parameters', 'resources.*.operation_roles'], true)) {
                continue;
            }
            if (is_array($value) && is_array($schema[$key]) && !array_is_list($value) && !array_is_list($schema[$key])) {
                $this->rejectUnknownKeys($value, $schema[$key], $current);
            }
        }
    }

    /**
     * Recursive map merge with list replacement, matching framework config
     * expectations for roles, formats, widths and extension allowlists.
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private function merge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (isset($base[$key]) && is_array($base[$key]) && is_array($value) && !array_is_list($base[$key]) && !array_is_list($value)) {
                $base[$key] = $this->merge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }
}

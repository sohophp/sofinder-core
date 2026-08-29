<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Usage;

use SohoPHP\SoFinder\Contract\StorageUsageProviderInterface;
use SohoPHP\SoFinder\Contract\UsageTrackerInterface;
use SohoPHP\SoFinder\Exception\SoFinderException;
use SohoPHP\SoFinder\Value\ResourceStorage;

final readonly class PersistentUsageTracker implements UsageTrackerInterface
{
    public function __construct(private string $directory)
    {
    }

    public function usage(ResourceStorage $resource): int
    {
        return $this->locked($resource, fn (string $stateFile): int => $this->loadOrScan($resource, $stateFile));
    }

    public function recalculate(ResourceStorage $resource): int
    {
        return $this->locked($resource, function (string $stateFile) use ($resource): int {
            $bytes = $this->scan($resource);
            $this->write($stateFile, $bytes, false);

            return $bytes;
        });
    }

    public function mutate(ResourceStorage $resource, callable $operation): mixed
    {
        return $this->locked($resource, function (string $stateFile) use ($resource, $operation): mixed {
            $current = $this->loadOrScan($resource, $stateFile);
            $this->write($stateFile, $current, true);
            $result = $operation($current);
            if (!is_array($result) || !array_key_exists('value', $result) || !isset($result['delta']) || !is_int($result['delta'])) {
                throw new \LogicException('A tracked storage mutation must return value and integer delta fields.');
            }
            $this->write($stateFile, max(0, $current + $result['delta']), false);

            return $result['value'];
        });
    }

    /**
     * @template T
     * @param callable(string): T $callback
     * @return T
     */
    private function locked(ResourceStorage $resource, callable $callback): mixed
    {
        $this->ensureDirectory();
        $key = hash('sha256', $resource->resource->name . "\0" . $resource->resource->root);
        $lock = @fopen($this->directory . '/' . $key . '.lock', 'c+b');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new SoFinderException('Unable to lock the resource usage state.', 'quota_check_failed', 500);
        }
        try {
            return $callback($this->directory . '/' . $key . '.json');
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function loadOrScan(ResourceStorage $resource, string $stateFile): int
    {
        $state = $this->read($stateFile);
        if ($state !== null && $state['dirty'] === false) {
            return $state['bytes'];
        }
        $bytes = $this->scan($resource);
        $this->write($stateFile, $bytes, false);

        return $bytes;
    }

    private function scan(ResourceStorage $resource): int
    {
        if (!$resource->storage instanceof StorageUsageProviderInterface) {
            throw new SoFinderException(
                'This storage adapter cannot calculate usage; provide a persisted usage baseline before enabling quotas.',
                'usage_scan_unsupported',
                501,
            );
        }

        return $resource->storage->usage();
    }

    /** @return array{bytes:int,dirty:bool}|null */
    private function read(string $file): ?array
    {
        $json = @file_get_contents($file);
        if ($json === false) {
            return null;
        }
        try {
            $data = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($data) && isset($data['bytes'], $data['dirty']) && is_int($data['bytes']) && is_bool($data['dirty']) && $data['bytes'] >= 0
            ? ['bytes' => $data['bytes'], 'dirty' => $data['dirty']]
            : null;
    }

    private function write(string $file, int $bytes, bool $dirty): void
    {
        $temporary = $file . '.tmp-' . bin2hex(random_bytes(6));
        $payload = json_encode(['version' => 1, 'bytes' => $bytes, 'dirty' => $dirty, 'updatedAt' => time()], JSON_THROW_ON_ERROR);
        if (@file_put_contents($temporary, $payload, LOCK_EX) === false || !@rename($temporary, $file)) {
            @unlink($temporary);
            throw new SoFinderException('Unable to persist the resource usage state.', 'quota_check_failed', 500);
        }
        @chmod($file, 0660);
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0770, true) && !is_dir($this->directory)) {
            throw new SoFinderException('Unable to create the private usage directory.', 'quota_check_failed', 500);
        }
    }
}

<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Health;

use SohoPHP\SoFinder\Contract\HealthCheckInterface;
use SohoPHP\SoFinder\Value\HealthCheckResult;

final readonly class RuntimeHealthCheck implements HealthCheckInterface
{
    /**
     * @param list<string> $directories
     * @param list<string> $assets
     */
    public function __construct(private array $directories, private array $assets)
    {
    }

    public function check(): HealthCheckResult
    {
        foreach ($this->directories as $directory) {
            if (!$this->directoryReady($directory)) {
                return new HealthCheckResult('runtime', 'down', 'A private runtime directory is missing or not writable.');
            }
        }
        foreach ($this->assets as $asset) {
            if (!is_file($asset) || !is_readable($asset)) {
                return new HealthCheckResult('runtime', 'down', 'A required browser asset is missing or not readable.');
            }
        }

        return new HealthCheckResult('runtime', 'ready', 'Runtime directories and browser assets are available.');
    }

    private function directoryReady(string $directory): bool
    {
        $candidate = $directory;
        while (!is_dir($candidate)) {
            $parent = dirname($candidate);
            if ($parent === $candidate) return false;
            $candidate = $parent;
        }

        return is_writable($candidate);
    }
}

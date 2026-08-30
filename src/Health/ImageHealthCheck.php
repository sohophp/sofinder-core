<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Health;

use SohoPHP\SoFinder\Contract\HealthCheckInterface;
use SohoPHP\SoFinder\Contract\ImageCapabilityProviderInterface;
use SohoPHP\SoFinder\Image\ImageFormatRegistry;
use SohoPHP\SoFinder\ResourceRegistry;
use SohoPHP\SoFinder\Value\HealthCheckResult;

final class ImageHealthCheck implements HealthCheckInterface
{
    public function __construct(
        private readonly ImageCapabilityProviderInterface $images,
        private readonly ImageFormatRegistry $formats,
        private readonly ResourceRegistry $resources,
    ) {
    }

    public function check(): HealthCheckResult
    {
        $unsupported = [];
        foreach ($this->resources->all() as $storage) {
            foreach ($storage->resource->allowedExtensions as $extension) {
                if ($this->formats->formatForExtension($extension) !== null && !$this->images->supportsExtension($extension)) {
                    $unsupported[] = $storage->resource->name . ':.' . strtolower($extension);
                }
            }
        }

        return $unsupported === []
            ? new HealthCheckResult('image', 'ready', sprintf('Image driver %s supports every configured image format.', $this->images->driver()))
            : new HealthCheckResult('image', 'down', 'Configured image formats are missing a decoder: ' . implode(', ', array_slice($unsupported, 0, 10)) . '.');
    }
}

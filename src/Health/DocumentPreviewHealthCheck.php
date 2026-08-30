<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Health;

use SohoPHP\SoFinder\Contract\HealthCheckInterface;
use SohoPHP\SoFinder\Value\HealthCheckResult;

final class DocumentPreviewHealthCheck implements HealthCheckInterface
{
    public function __construct(private readonly bool $pdfEnabled, private readonly bool $officeEnabled, private readonly string $officeBinary) {}

    public function check(): HealthCheckResult
    {
        if ($this->officeEnabled && (!function_exists('proc_open') || !is_file($this->officeBinary) || !is_executable($this->officeBinary))) {
            return new HealthCheckResult('document-preview', 'down', 'Office preview is enabled but LibreOffice is unavailable.');
        }
        if (!$this->pdfEnabled && !$this->officeEnabled) return new HealthCheckResult('document-preview', 'degraded', 'Document preview is disabled.');

        return new HealthCheckResult('document-preview', 'ready', $this->officeEnabled ? 'PDF and Office preview are available.' : 'PDF preview is available.');
    }
}

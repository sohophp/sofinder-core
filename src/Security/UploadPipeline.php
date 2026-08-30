<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Security;

use SohoPHP\SoFinder\Contract\FileInspectorInterface;
use SohoPHP\SoFinder\Contract\UploadScannerInterface;
use SohoPHP\SoFinder\Exception\SoFinderException;
use SohoPHP\SoFinder\Value\InspectedFile;
use SohoPHP\SoFinder\Value\ResourceType;

final class UploadPipeline
{
    public function __construct(
        private readonly FileInspectorInterface $inspector,
        private readonly string $quarantineDirectory,
        /** @var iterable<UploadScannerInterface> */
        private readonly iterable $scanners = [],
    ) {
    }

    /**
     * @param resource $stream
     * @return array{path: string, inspection: InspectedFile}
     */
    public function quarantine(mixed $stream, string $fileName, ResourceType $resource): array
    {
        if (!is_resource($stream)) {
            throw new \InvalidArgumentException('UploadPipeline expects a stream resource.');
        }
        if (!is_dir($this->quarantineDirectory) && !@mkdir($this->quarantineDirectory, 0770, true) && !is_dir($this->quarantineDirectory)) {
            throw new SoFinderException('Unable to create the upload quarantine.', 'upload_quarantine_failed', 500);
        }
        $temporary = tempnam($this->quarantineDirectory, '.sofinder-quarantine-');
        if ($temporary === false) {
            throw new SoFinderException('Unable to create an upload quarantine file.', 'upload_quarantine_failed', 500);
        }
        $output = null;
        try {
            $output = @fopen($temporary, 'wb');
            if ($output === false) {
                throw new SoFinderException('Unable to prepare the upload quarantine.', 'upload_quarantine_failed', 500);
            }
            $bytes = 0;
            while (!feof($stream)) {
                $chunk = fread($stream, 65_536);
                if ($chunk === false) {
                    throw new SoFinderException('Unable to read the uploaded file.', 'invalid_upload', 400);
                }
                $bytes += strlen($chunk);
                if ($bytes > $resource->maxSize) {
                    throw new SoFinderException('The uploaded file exceeds the configured size limit.', 'file_too_large', 413);
                }
                if ($chunk !== '' && fwrite($output, $chunk) !== strlen($chunk)) {
                    throw new SoFinderException('Unable to write the upload quarantine.', 'upload_quarantine_failed', 500);
                }
            }
            fclose($output);
            $output = null;
            @chmod($temporary, 0600);

            $inspection = $this->inspector->inspect($temporary, $fileName, $resource);
            foreach ($this->scanners as $scanner) {
                $scanner->scan($temporary, $fileName, $resource, $inspection);
            }

            return ['path' => $temporary, 'inspection' => $inspection];
        } catch (\Throwable $exception) {
            @unlink($temporary);
            throw $exception;
        } finally {
            if (is_resource($output)) {
                fclose($output);
            }
        }
    }
}

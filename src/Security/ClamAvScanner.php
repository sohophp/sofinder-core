<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Security;

use Psr\Log\LoggerInterface;
use SohoPHP\SoFinder\Contract\HealthCheckInterface;
use SohoPHP\SoFinder\Contract\MetricsStoreInterface;
use SohoPHP\SoFinder\Contract\MalwareScanStatusStoreInterface;
use SohoPHP\SoFinder\Contract\UploadScannerInterface;
use SohoPHP\SoFinder\Exception\SoFinderException;
use SohoPHP\SoFinder\Value\HealthCheckResult;
use SohoPHP\SoFinder\Value\InspectedFile;
use SohoPHP\SoFinder\Value\ResourceType;

/** Streams quarantine files to clamd without invoking a shell command. */
final readonly class ClamAvScanner implements UploadScannerInterface, HealthCheckInterface
{
    /** @param (\Closure(string,float):mixed)|null $connector Testable stream connector; omit in applications. */
    public function __construct(
        private string $endpoint = 'tcp://127.0.0.1:3310',
        private float $timeoutSeconds = 5.0,
        private ?\Closure $connector = null,
        private ?MetricsStoreInterface $metrics = null,
        private ?MalwareScanStatusStoreInterface $statusStore = null,
        private ?LoggerInterface $logger = null,
        private bool $enabled = true,
    ) {
        if (preg_match('#^(?:tcp://[A-Za-z0-9.:-]+|unix:///[^\x00-\x1F]+)$#D', $endpoint) !== 1 || $timeoutSeconds <= 0 || $timeoutSeconds > 60) {
            throw new \InvalidArgumentException('The ClamAV endpoint or timeout is invalid.');
        }
    }

    public function scan(string $path, string $fileName, ResourceType $resource, InspectedFile $inspection): void
    {
        if (!$this->enabled) return;

        $started = hrtime(true);
        try {
            $scanId = $this->statusStore?->start($fileName, $resource->name, $inspection->size);
        } catch (\Throwable) {
            $scanId = null;
        }
        try {
            $socket = $this->connect();
            $input = @fopen($path, 'rb');
            if ($input === false) {
                fclose($socket);
                throw new SoFinderException('The quarantined upload cannot be scanned.', 'malware_scanner_unavailable', 503);
            }
            try {
                $this->write($socket, "zINSTREAM\0");
                while (!feof($input)) {
                    $chunk = fread($input, 8192);
                    if ($chunk === false) throw new SoFinderException('The quarantined upload cannot be scanned.', 'malware_scanner_unavailable', 503);
                    if ($chunk !== '') $this->write($socket, pack('N', strlen($chunk)) . $chunk);
                }
                $this->write($socket, pack('N', 0));
                $response = $this->response($socket);
            } finally {
                fclose($input);
                fclose($socket);
            }
            if (str_contains($response, ' FOUND')) {
                throw new SoFinderException('The upload was rejected by malware scanning.', 'malware_detected', 415);
            }
            if (!str_ends_with($response, ' OK')) {
                throw new SoFinderException('The malware scanner could not verify the upload.', 'malware_scanner_unavailable', 503);
            }
            $this->record($scanId, 'passed', null, $resource, $fileName, $inspection->size, $started);
        } catch (\Throwable $exception) {
            $code = $exception instanceof SoFinderException ? $exception->errorCode : 'malware_scanner_unavailable';
            $this->record($scanId, $code === 'malware_detected' ? 'quarantined' : 'failed', $code, $resource, $fileName, $inspection->size, $started);
            throw $exception;
        }
    }

    public function check(): HealthCheckResult
    {
        if (!$this->enabled) {
            return new HealthCheckResult('clamav-disabled', 'ready', 'ClamAV scanning is disabled by configuration.');
        }

        try {
            $socket = $this->connect();
            try {
                $this->write($socket, "zPING\0");
                $ready = trim($this->response($socket)) === 'PONG';
            } finally { fclose($socket); }
            return new HealthCheckResult('clamav-' . substr(hash('sha256', $this->endpoint), 0, 8), $ready ? 'ready' : 'down', $ready ? 'ClamAV responded.' : 'ClamAV returned an unexpected response.');
        } catch (\Throwable) {
            return new HealthCheckResult('clamav-' . substr(hash('sha256', $this->endpoint), 0, 8), 'down', 'ClamAV is unavailable.');
        }
    }

    /** @return resource */
    private function connect(): mixed
    {
        if ($this->connector !== null) $socket = ($this->connector)($this->endpoint, $this->timeoutSeconds);
        else $socket = @stream_socket_client($this->endpoint, $errorCode, $errorMessage, $this->timeoutSeconds, STREAM_CLIENT_CONNECT);
        if (!is_resource($socket)) throw new SoFinderException('The malware scanner is unavailable.', 'malware_scanner_unavailable', 503);
        stream_set_timeout($socket, (int) $this->timeoutSeconds, (int) (($this->timeoutSeconds - (int) $this->timeoutSeconds) * 1_000_000));
        return $socket;
    }

    /** @param resource $stream */
    private function write(mixed $stream, string $data): void
    {
        $offset = 0;
        while ($offset < strlen($data)) {
            $written = fwrite($stream, substr($data, $offset));
            if ($written === false || $written === 0) throw new SoFinderException('The malware scanner connection failed.', 'malware_scanner_unavailable', 503);
            $offset += $written;
        }
    }

    /** @param resource $stream */
    private function response(mixed $stream): string
    {
        $response = '';
        while (!feof($stream) && strlen($response) < 1024) {
            $chunk = fread($stream, 256);
            if ($chunk === false) {
                $meta = stream_get_meta_data($stream);
                if ($meta['timed_out'] === true) throw new SoFinderException('The malware scanner timed out.', 'malware_scanner_timeout', 503);
                throw new SoFinderException('The malware scanner connection failed.', 'malware_scanner_unavailable', 503);
            }
            $response .= $chunk;
            if (str_contains($response, "\0") || str_contains($response, "\n")) break;
        }
        $meta = stream_get_meta_data($stream);
        if ($meta['timed_out'] === true) throw new SoFinderException('The malware scanner timed out.', 'malware_scanner_timeout', 503);
        return trim($response, "\0\r\n ");
    }

    private function record(?string $scanId, string $status, ?string $code, ResourceType $resource, string $fileName, int $bytes, int $started): void
    {
        $duration = max(0, (int) round((hrtime(true) - $started) / 1_000_000));
        try {
            if ($scanId !== null) $this->statusStore?->finish($scanId, $status, $code, $duration);
        } catch (\Throwable) {
        }
        try {
            $this->metrics?->increment('sofinder_malware_scans_total', ['provider' => 'clamav', 'result' => $status]);
            $this->metrics?->increment('sofinder_malware_scan_bytes_total', ['provider' => 'clamav', 'result' => $status], max(0, $bytes));
            $this->metrics?->increment('sofinder_malware_scan_duration_milliseconds_total', ['provider' => 'clamav', 'result' => $status], $duration);
            if ($code === 'malware_scanner_timeout') $this->metrics?->increment('sofinder_malware_scan_timeouts_total', ['provider' => 'clamav']);
        } catch (\Throwable) {
        }
        $context = ['provider' => 'clamav', 'result' => $status, 'code' => $code, 'resource' => $resource->name, 'file_name' => $fileName, 'bytes' => $bytes, 'duration_ms' => $duration];
        try {
            if ($status === 'passed') $this->logger?->info('SoFinder malware scan completed.', $context);
            else $this->logger?->warning('SoFinder malware scan rejected or failed.', $context);
        } catch (\Throwable) {
        }
    }
}

<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Value;

final readonly class HealthCheckResult implements \JsonSerializable
{
    public function __construct(
        public string $name,
        public string $status,
        public string $message = '',
    ) {
        if (preg_match('/^[a-z][a-z0-9._-]{1,63}$/D', $name) !== 1) {
            throw new \InvalidArgumentException('Health check names must contain 2-64 lowercase letters, numbers, dots, underscores or hyphens.');
        }
        if (!in_array($status, ['ready', 'degraded', 'down'], true)) {
            throw new \InvalidArgumentException('Health check status must be ready, degraded or down.');
        }
        if (strlen($message) > 256 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $message) === 1) {
            throw new \InvalidArgumentException('Health check messages must be short printable text.');
        }
    }

    /** @return array{name:string,status:string,message:string} */
    public function jsonSerialize(): array
    {
        return ['name' => $this->name, 'status' => $this->status, 'message' => $this->message];
    }
}

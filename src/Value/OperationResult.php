<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Value;

final class OperationResult implements \JsonSerializable
{
    /** @param array<string, mixed> $data */
    private function __construct(
        public readonly bool $success,
        public readonly array $data = [],
        public readonly ?string $errorCode = null,
        public readonly ?string $message = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function success(array $data = []): self
    {
        return new self(true, $data);
    }

    public static function failure(string $code, string $message): self
    {
        return new self(false, [], $code, $message);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->success
            ? ['success' => true, 'data' => $this->data]
            : ['success' => false, 'error' => ['code' => $this->errorCode, 'message' => $this->message]];
    }
}

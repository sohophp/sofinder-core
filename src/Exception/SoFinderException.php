<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Exception;

class SoFinderException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'operation_failed',
        public readonly int $httpStatus = 400,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}

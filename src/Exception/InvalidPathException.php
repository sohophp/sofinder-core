<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Exception;

final class InvalidPathException extends SoFinderException
{
    public function __construct(string $message = 'The requested path is invalid.')
    {
        parent::__construct($message, 'invalid_path', 400);
    }
}

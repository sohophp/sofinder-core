<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Exception;

final class ConflictException extends SoFinderException
{
    public function __construct(string $message = 'An entry with this name already exists.')
    {
        parent::__construct($message, 'conflict', 409);
    }
}

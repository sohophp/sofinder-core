<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Exception;

final class NotFoundException extends SoFinderException
{
    public function __construct(string $message = 'The requested entry was not found.')
    {
        parent::__construct($message, 'not_found', 404);
    }
}

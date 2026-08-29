<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Exception;

final class AccessDeniedException extends SoFinderException
{
    public function __construct(string $message = 'You are not allowed to perform this operation.')
    {
        parent::__construct($message, 'access_denied', 403);
    }
}

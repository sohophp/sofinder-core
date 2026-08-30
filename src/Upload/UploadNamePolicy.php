<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Upload;

final class UploadNamePolicy
{
    public function __construct(private readonly bool $lowercaseExtensions = true)
    {
    }

    public function normalize(string $name): string
    {
        if (!$this->lowercaseExtensions) {
            return $name;
        }
        $separator = strrpos($name, '.');
        if ($separator === false || $separator === 0 || $separator === strlen($name) - 1) {
            return $name;
        }

        return substr($name, 0, $separator + 1) . strtolower(substr($name, $separator + 1));
    }
}

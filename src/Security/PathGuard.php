<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Security;

use SohoPHP\SoFinder\Exception\InvalidPathException;

final class PathGuard
{
    public function normalize(string $path): string
    {
        if ($path !== trim($path) || preg_match('//u', $path) !== 1 || str_contains($path, "\0") || preg_match('/[\x00-\x1F\x7F]/u', $path) === 1) {
            throw new InvalidPathException();
        }

        $path = str_replace('\\', '/', $path);
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '') {
                continue;
            }
            if ($segment === '.' || $segment === '..') {
                throw new InvalidPathException();
            }
            $this->assertName($segment);
            $segments[] = $segment;
        }

        return implode('/', $segments);
    }

    public function join(string $directory, string $name): string
    {
        $this->assertName($name);
        $directory = $this->normalize($directory);

        return $directory === '' ? $name : $directory . '/' . $name;
    }

    public function assertName(string $name): void
    {
        $reserved = '/^(?:CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|$)/iu';
        if (
            preg_match('//u', $name) !== 1
            || $name === ''
            || $name !== trim($name)
            || $name === '.'
            || $name === '..'
            || str_starts_with($name, '.')
            || str_ends_with($name, '.')
            || str_contains($name, '/')
            || str_contains($name, '\\')
            || str_contains($name, "\0")
            || preg_match('/[<>:"|?*\x00-\x1F\x7F\x{200B}\x{2044}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{2215}\x{29F8}\x{FEFF}\x{FF0F}\x{FF3C}\x{FDD0}-\x{FDEF}\x{FFFE}\x{FFFF}]/u', $name) === 1
            || preg_match($reserved, $name) === 1
        ) {
            throw new InvalidPathException('The file or folder name contains an unsafe or unsupported character or reserved name.');
        }
    }
}

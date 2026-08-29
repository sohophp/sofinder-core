<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Security;

use SohoPHP\SoFinder\Contract\RequestGateStoreInterface;
use SohoPHP\SoFinder\Exception\SoFinderException;

final readonly class LocalRequestGateStore implements RequestGateStoreInterface
{
    public function __construct(private string $directory)
    {
    }

    public function mutate(string $group, string $actor, callable $callback): array
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0770, true) && !is_dir($this->directory)) {
            throw new SoFinderException('The request limiter is unavailable.', 'rate_limiter_unavailable', 503);
        }
        $file = rtrim($this->directory, '/') . '/' . preg_replace('/[^a-z0-9_-]/i', '-', $group) . '-' . hash('sha256', $actor) . '.json';
        $stream = @fopen($file, 'c+b');
        if ($stream === false || !flock($stream, LOCK_EX)) {
            if (is_resource($stream)) fclose($stream);
            throw new SoFinderException('The request limiter is unavailable.', 'rate_limiter_unavailable', 503);
        }
        try {
            $json = stream_get_contents($stream);
            $decoded = $json === false || $json === '' ? [] : json_decode($json, true);
            $state = $callback(is_array($decoded) ? $decoded : []);
            rewind($stream);
            if (!ftruncate($stream, 0) || fwrite($stream, json_encode($state, JSON_THROW_ON_ERROR)) === false || !fflush($stream)) {
                throw new SoFinderException('The request limiter state could not be saved.', 'rate_limiter_unavailable', 503);
            }

            return $state;
        } finally {
            flock($stream, LOCK_UN);
            fclose($stream);
        }
    }
}

<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Testing;

use SohoPHP\SoFinder\Contract\StorageAdapterInterface;
use SohoPHP\SoFinder\Exception\ConflictException;
use SohoPHP\SoFinder\Exception\NotFoundException;
use SohoPHP\SoFinder\Value\Entry;
use SohoPHP\SoFinder\Value\ListQuery;

/**
 * Mutating compatibility probe for a dedicated test storage resource.
 *
 * The verifier creates and removes one randomized top-level directory. Never
 * run it against a resource where that namespace can be supplied by users.
 */
final class StorageAdapterContractVerifier
{
    public static function verify(StorageAdapterInterface $adapter, ?string $namespace = null): void
    {
        $namespace ??= 'sofinder-contract-' . bin2hex(random_bytes(8));
        if (preg_match('/^sofinder-contract-[a-f0-9]{16}$/D', $namespace) !== 1) {
            throw new \InvalidArgumentException('The contract namespace must use the isolated sofinder-contract-<16 lowercase hex characters> form.');
        }

        $source = $namespace . '/source.txt';
        $copy = $namespace . '/copy.txt';
        $moved = $namespace . '/moved.txt';
        $nested = $namespace . '/nested';
        $cleanup = [$moved, $copy, $source, $nested, $namespace];

        try {
            self::assertEntry($adapter->createDirectory($namespace), $namespace, true);
            self::assertEntry($adapter->createDirectory($nested), $nested, true);

            $stream = fopen('php://temp', 'w+b');
            if (!is_resource($stream)) {
                throw new \RuntimeException('Unable to create the contract input stream.');
            }
            fwrite($stream, 'sofinder-contract');
            rewind($stream);
            try {
                self::assertEntry($adapter->writeStream($source, $stream), $source, false);
            } finally {
                fclose($stream);
            }

            self::assert($adapter->size($source) === 17, 'size() must return the stored byte length.');
            self::assertEntry($adapter->entry($source), $source, false);
            $input = $adapter->readStream($source);
            self::assert(is_resource($input), 'readStream() must return a stream resource.');
            try {
                self::assert(stream_get_contents($input) === 'sofinder-contract', 'readStream() must preserve file bytes.');
            } finally {
                if (is_resource($input)) {
                    fclose($input);
                }
            }

            $listing = $adapter->list(new ListQuery($namespace, limit: 100));
            self::assert(in_array($source, array_column($listing->entries, 'path'), true), 'list() must include a written child.');
            self::assert(in_array($nested, array_column($listing->entries, 'path'), true), 'list() must include a created directory.');

            self::expectConflict(static function () use ($adapter, $source): void {
                $duplicate = fopen('php://temp', 'w+b');
                if (!is_resource($duplicate)) {
                    throw new \RuntimeException('Unable to create the duplicate contract stream.');
                }
                try {
                    fwrite($duplicate, 'duplicate');
                    rewind($duplicate);
                    $adapter->writeStream($source, $duplicate);
                } finally {
                    fclose($duplicate);
                }
            });

            self::assertEntry($adapter->copy($source, $copy), $copy, false);
            self::assertEntry($adapter->move($copy, $moved), $moved, false);
            self::expectNotFound(static fn (): Entry => $adapter->entry($copy));

            $capabilities = $adapter->capabilities();
            $publicUrl = $adapter->publicUrl($source);
            self::assert(!$capabilities->publicUrl || is_string($publicUrl), 'publicUrl capability requires a URL for stored files.');
            self::assert($capabilities->publicUrl || $publicUrl === null, 'publicUrl() must be null when the capability is disabled.');

            $adapter->delete($moved);
            $adapter->delete($source);
            $adapter->delete($nested);
            $adapter->delete($namespace);
            $cleanup = [];
            self::expectNotFound(static fn (): Entry => $adapter->entry($source));
        } finally {
            foreach ($cleanup as $path) {
                try {
                    $adapter->delete($path);
                } catch (NotFoundException) {
                    // The happy path or a partial operation may already have removed it.
                } catch (\Throwable) {
                    // Preserve the original contract failure; cleanup is best effort.
                }
            }
        }
    }

    private static function assertEntry(Entry $entry, string $path, bool $directory): void
    {
        self::assert($entry->path === $path, sprintf('Entry path must be "%s".', $path));
        self::assert($entry->directory === $directory, sprintf('Entry "%s" has an incorrect directory flag.', $path));
    }

    /** @param callable(): mixed $operation */
    private static function expectConflict(callable $operation): void
    {
        try {
            $operation();
        } catch (ConflictException) {
            return;
        }
        throw new \UnexpectedValueException('Creating an existing entry without overwrite must throw ConflictException.');
    }

    /** @param callable(): mixed $operation */
    private static function expectNotFound(callable $operation): void
    {
        try {
            $operation();
        } catch (NotFoundException) {
            return;
        }
        throw new \UnexpectedValueException('Reading a missing entry must throw NotFoundException.');
    }

    private static function assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \UnexpectedValueException($message);
        }
    }
}

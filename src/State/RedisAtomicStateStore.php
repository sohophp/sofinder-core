<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\State;

use SohoPHP\SoFinder\Contract\AtomicStateStoreInterface;
use SohoPHP\SoFinder\Exception\SoFinderException;

/** Shared state backed by ext-redis. The supplied connection remains host-owned. */
final readonly class RedisAtomicStateStore implements AtomicStateStoreInterface
{
    public function __construct(
        private \Redis $redis,
        private string $prefix = 'sofinder:',
        private int $lockTtlMilliseconds = 60_000,
        private int $waitMilliseconds = 2_000,
    ) {
        if ($prefix === '' || strlen($prefix) > 128 || $lockTtlMilliseconds < 1000 || $waitMilliseconds < 0) {
            throw new \InvalidArgumentException('The Redis state-store settings are invalid.');
        }
    }

    public function get(string $namespace, string $key): array
    {
        $json = $this->redis->get($this->key($namespace, $key));

        return is_string($json) ? $this->decode($json) : [];
    }

    public function mutate(string $namespace, string $key, callable $callback): array
    {
        $stateKey = $this->key($namespace, $key);
        $lockKey = $stateKey . ':lock';
        $token = bin2hex(random_bytes(16));
        $deadline = microtime(true) + ($this->waitMilliseconds / 1000);
        do {
            if ($this->redis->set($lockKey, $token, ['nx', 'px' => $this->lockTtlMilliseconds])) break;
            usleep(20_000);
        } while (microtime(true) < $deadline);
        if ($this->redis->get($lockKey) !== $token) {
            throw new SoFinderException('The shared Redis state is busy.', 'shared_state_unavailable', 503);
        }
        try {
            $json = $this->redis->get($stateKey);
            $state = $callback(is_string($json) ? $this->decode($json) : []);
            $payload = json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if ($this->redis->get($lockKey) !== $token) {
                throw new SoFinderException('The shared Redis state lock expired before the mutation completed.', 'shared_state_unavailable', 503);
            }
            if (!$this->redis->set($stateKey, $payload)) {
                throw new SoFinderException('The shared Redis state could not be saved.', 'shared_state_unavailable', 503);
            }

            return $state;
        } finally {
            $this->redis->eval('if redis.call("get", KEYS[1]) == ARGV[1] then return redis.call("del", KEYS[1]) else return 0 end', [$lockKey, $token], 1);
        }
    }

    private function key(string $namespace, string $key): string
    {
        if (preg_match('/^[a-z][a-z0-9._-]{1,63}$/D', $namespace) !== 1) {
            throw new \InvalidArgumentException('The shared state namespace is invalid.');
        }

        return $this->prefix . $namespace . ':' . hash('sha256', $key);
    }

    /** @return array<string,mixed> */
    private function decode(string $json): array
    {
        try { $state = json_decode($json, true, 32, JSON_THROW_ON_ERROR); }
        catch (\JsonException $exception) { throw new SoFinderException('The shared Redis state is corrupted.', 'shared_state_corrupted', 500, $exception); }

        return is_array($state) ? $state : [];
    }
}

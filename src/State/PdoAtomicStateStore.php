<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\State;

use SohoPHP\SoFinder\Contract\AtomicStateStoreInterface;
use SohoPHP\SoFinder\Exception\SoFinderException;

final class PdoAtomicStateStore implements AtomicStateStoreInterface
{
    private bool $installed = false;
    private readonly string $table;

    public function __construct(private readonly \PDO $pdo, string $table = 'sofinder_state')
    {
        if (preg_match('/^[a-z][a-z0-9_]{1,47}$/D', $table) !== 1) {
            throw new \InvalidArgumentException('The SoFinder state table name is invalid.');
        }
        $this->table = $table;
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    public function install(): void
    {
        $this->pdo->exec(sprintf(
            'CREATE TABLE IF NOT EXISTS %s (namespace VARCHAR(64) NOT NULL, state_key CHAR(64) NOT NULL, state_json TEXT NOT NULL, version BIGINT NOT NULL, updated_at BIGINT NOT NULL, PRIMARY KEY (namespace, state_key))',
            $this->table,
        ));
        $this->installed = true;
    }

    public function get(string $namespace, string $key): array
    {
        $this->prepare($namespace);
        $statement = $this->pdo->prepare(sprintf('SELECT state_json FROM %s WHERE namespace = :namespace AND state_key = :key', $this->table));
        $statement->execute(['namespace' => $namespace, 'key' => hash('sha256', $key)]);
        $json = $statement->fetchColumn();

        return is_string($json) ? $this->decode($json) : [];
    }

    public function mutate(string $namespace, string $key, callable $callback): array
    {
        $this->prepare($namespace);
        $hash = hash('sha256', $key);
        $this->ensureRow($namespace, $hash);
        $sqlite = $this->driver() === 'sqlite';
        $transactionStarted = false;
        try {
            if ($sqlite) {
                $this->pdo->exec('BEGIN IMMEDIATE');
            } else {
                $this->pdo->beginTransaction();
            }
            $transactionStarted = true;
            $sql = sprintf('SELECT state_json FROM %s WHERE namespace = :namespace AND state_key = :key%s', $this->table, $sqlite ? '' : ' FOR UPDATE');
            $select = $this->pdo->prepare($sql);
            $select->execute(['namespace' => $namespace, 'key' => $hash]);
            $json = $select->fetchColumn();
            $state = $callback(is_string($json) ? $this->decode($json) : []);
            $update = $this->pdo->prepare(sprintf('UPDATE %s SET state_json = :json, version = version + 1, updated_at = :updated WHERE namespace = :namespace AND state_key = :key', $this->table));
            $update->execute([
                'json' => json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'updated' => time(), 'namespace' => $namespace, 'key' => $hash,
            ]);
            if ($sqlite) {
                $this->pdo->exec('COMMIT');
            } else {
                $this->pdo->commit();
            }
            $transactionStarted = false;

            return $state;
        } catch (\Throwable $exception) {
            if ($transactionStarted) {
                try {
                    if ($sqlite) $this->pdo->exec('ROLLBACK');
                    elseif ($this->pdo->inTransaction()) $this->pdo->rollBack();
                } catch (\Throwable) {
                }
            }
            if ($exception instanceof SoFinderException || $exception instanceof \InvalidArgumentException || $exception instanceof \LogicException) throw $exception;
            throw new SoFinderException('The shared database state is unavailable.', 'shared_state_unavailable', 503, $exception);
        }
    }

    private function prepare(string $namespace): void
    {
        if (preg_match('/^[a-z][a-z0-9._-]{1,63}$/D', $namespace) !== 1) {
            throw new \InvalidArgumentException('The shared state namespace is invalid.');
        }
        if (!$this->installed) $this->install();
    }

    private function ensureRow(string $namespace, string $key): void
    {
        $statement = $this->pdo->prepare(sprintf('INSERT INTO %s (namespace, state_key, state_json, version, updated_at) VALUES (:namespace, :key, :json, 0, :updated)', $this->table));
        try {
            $statement->execute(['namespace' => $namespace, 'key' => $key, 'json' => '{}', 'updated' => time()]);
        } catch (\PDOException $exception) {
            if (!in_array((string) $exception->getCode(), ['23000', '23505'], true)) throw $exception;
        }
    }

    /** @return array<string,mixed> */
    private function decode(string $json): array
    {
        try { $state = json_decode($json, true, 32, JSON_THROW_ON_ERROR); }
        catch (\JsonException $exception) { throw new SoFinderException('The shared database state is corrupted.', 'shared_state_corrupted', 500, $exception); }

        return is_array($state) ? $state : [];
    }

    private function driver(): string
    {
        return (string) $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
    }
}

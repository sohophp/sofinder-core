<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Maintenance;

use SohoPHP\SoFinder\Contract\ChunkUploadStoreInterface;
use SohoPHP\SoFinder\Contract\AtomicStateStoreInterface;
use SohoPHP\SoFinder\Contract\RecycleBinInterface;
use SohoPHP\SoFinder\Contract\UsageTrackerInterface;
use SohoPHP\SoFinder\ResourceRegistry;
use SohoPHP\SoFinder\Exception\SoFinderException;

final class MaintenanceRunner
{
    public function __construct(
        private readonly string $directory,
        private readonly ChunkUploadStoreInterface $chunks,
        private readonly RecycleBinInterface $trash,
        private readonly UsageTrackerInterface $usage,
        private readonly ResourceRegistry $resources,
        private readonly ?AtomicStateStoreInterface $state = null,
    ) {
    }

    public function run(MaintenanceTask $task, ?int $limit = null): MaintenanceResult
    {
        $token = $this->claim($task);
        if ($token === null) {
            $this->record($task, static fn (array $state): array => array_merge($state, ['status' => 'busy', 'updatedAt' => time()]), true);
            return new MaintenanceResult($task, false, 0);
        }

        try {
            $this->record($task, static fn (array $state): array => array_merge($state, ['status' => 'running', 'startedAt' => time(), 'updatedAt' => time(), 'attempts' => (int) ($state['attempts'] ?? 0) + 1, 'error' => null]));
            $result = match ($task) {
                MaintenanceTask::Uploads => $this->count($task, $this->chunks->cleanupExpired(true, $limit)),
                MaintenanceTask::Trash => $this->count($task, $this->trash->purgeExpired($limit)),
                MaintenanceTask::Usage => $this->recalculateUsage(),
            };
            $this->record($task, static fn (array $state): array => array_merge($state, ['status' => 'succeeded', 'finishedAt' => time(), 'updatedAt' => time(), 'processed' => $result->processed, 'details' => $result->details, 'error' => null]));

            return $result;
        } catch (\Throwable $exception) {
            $code = $exception instanceof SoFinderException ? $exception->errorCode : 'maintenance_failed';
            $this->record($task, static fn (array $state): array => array_merge($state, ['status' => 'failed', 'finishedAt' => time(), 'updatedAt' => time(), 'error' => ['code' => $code, 'message' => mb_substr($exception->getMessage(), 0, 300)]]));
            throw $exception;
        } finally {
            $this->release($task, $token);
        }
    }

    public function queued(MaintenanceTask $task): void
    {
        $this->record($task, static fn (array $state): array => array_merge($state, ['status' => 'queued', 'queuedAt' => time(), 'updatedAt' => time(), 'queued' => (int) ($state['queued'] ?? 0) + 1]));
    }

    /** @return array<string,array<string,mixed>> */
    public function status(): array
    {
        if ($this->state !== null) return $this->state->get('maintenance-status', 'global');
        $this->ensureDirectory();
        $file = $this->directory . '/status.json';
        $handle = @fopen($file, 'c+b');
        if ($handle === false) return [];
        try {
            if (!flock($handle, LOCK_SH)) return [];
            $contents = stream_get_contents($handle);
            flock($handle, LOCK_UN);
            if (!is_string($contents) || $contents === '') return [];
            $state = json_decode($contents, true);
            return is_array($state) ? $state : [];
        } finally {
            fclose($handle);
        }
    }

    private function count(MaintenanceTask $task, int $processed): MaintenanceResult
    {
        return new MaintenanceResult($task, true, $processed);
    }

    private function recalculateUsage(): MaintenanceResult
    {
        $details = [];
        foreach ($this->resources->all() as $resource) {
            $details[$resource->resource->name] = $this->usage->recalculate($resource);
        }

        return new MaintenanceResult(MaintenanceTask::Usage, true, count($details), $details);
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0770, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('Unable to create the SoFinder maintenance state directory.');
        }
    }

    /** @return resource|string|null */
    private function claim(MaintenanceTask $task): mixed
    {
        if ($this->state !== null) {
            $token = bin2hex(random_bytes(16));
            $claimed = false;
            $this->state->mutate('maintenance-lock', $task->value, static function (array $state) use ($token, &$claimed): array {
                if ((int) ($state['expiresAt'] ?? 0) > time()) return $state;
                $claimed = true;

                return ['token' => $token, 'expiresAt' => time() + 3600];
            });

            return $claimed ? $token : null;
        }
        $this->ensureDirectory();
        $lock = @fopen($this->directory . '/' . $task->value . '.lock', 'c+b');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) fclose($lock);
            return null;
        }
        return $lock;
    }

    /** @param resource|string $token */
    private function release(MaintenanceTask $task, mixed $token): void
    {
        if ($this->state !== null) {
            if (!is_string($token)) return;
            $this->state->mutate('maintenance-lock', $task->value, static fn (array $state): array => ($state['token'] ?? null) === $token ? [] : $state);
            return;
        }
        if (is_resource($token)) {
            flock($token, LOCK_UN);
            fclose($token);
        }
    }

    /** @param callable(array<string,mixed>):array<string,mixed> $update */
    private function record(MaintenanceTask $task, callable $update, bool $incrementSkip = false): void
    {
        if ($this->state !== null) {
            $this->state->mutate('maintenance-status', 'global', static function (array $all) use ($task, $update, $incrementSkip): array {
                $current = is_array($all[$task->value] ?? null) ? $all[$task->value] : [];
                $next = $update($current);
                if ($incrementSkip) $next['lockSkips'] = (int) ($current['lockSkips'] ?? 0) + 1;
                $all[$task->value] = $next;

                return $all;
            });
            return;
        }
        $this->ensureDirectory();
        $file = $this->directory . '/status.json';
        $handle = @fopen($file, 'c+b');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            if (is_resource($handle)) fclose($handle);
            return;
        }
        try {
            $contents = stream_get_contents($handle);
            $all = is_string($contents) && $contents !== '' ? json_decode($contents, true) : [];
            if (!is_array($all)) $all = [];
            $current = is_array($all[$task->value] ?? null) ? $all[$task->value] : [];
            $next = $update($current);
            if ($incrementSkip) $next['lockSkips'] = (int) ($current['lockSkips'] ?? 0) + 1;
            $all[$task->value] = $next;
            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, json_encode($all, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            fflush($handle);
        } catch (\Throwable) {
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}

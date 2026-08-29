<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Preview;

use SohoPHP\SoFinder\Contract\ActorProviderInterface;
use SohoPHP\SoFinder\Contract\AtomicStateStoreInterface;
use SohoPHP\SoFinder\Contract\MetricsStoreInterface;
use SohoPHP\SoFinder\Exception\SoFinderException;

final readonly class DocumentPreviewJobManager
{
    public function __construct(
        private DocumentPreviewManager $previews,
        private ActorProviderInterface $actors,
        private string $stateFile,
        private string $mode = 'auto',
        private int $jobTtlSeconds = 86400,
        private int $cacheTtlSeconds = 604800,
        private ?object $bus = null,
        private ?AtomicStateStoreInterface $state = null,
        private ?MetricsStoreInterface $metrics = null,
        private ?\Closure $clock = null,
    ) {
        if (!in_array($mode, ['auto', 'inline', 'messenger'], true) || $jobTtlSeconds < 60 || $cacheTtlSeconds < 60) throw new \InvalidArgumentException('The document preview job configuration is invalid.');
        if ($mode === 'messenger' && !$this->hasBus()) throw new \InvalidArgumentException('Document preview messenger mode requires messenger.default_bus.');
    }

    /** @return array<string,mixed> */
    public function prepare(string $resource, string $path, bool $retry = false): array
    {
        $description = $this->previews->describe($resource, $path);
        if ($description['source'] === 'pdf' || $description['cached'] || !$this->asynchronous()) {
            $startedAt = $this->now();
            $this->previews->preview($resource, $path);
            $finishedAt = $this->now();
            return ['id' => '', 'status' => 'ready', 'retryAfter' => 0, 'error' => null, 'source' => $description['source'], 'key' => $description['key'], 'resource' => $resource, 'path' => $path, 'mode' => 'inline', 'cached' => $description['cached'], 'createdAt' => $startedAt, 'startedAt' => $startedAt, 'updatedAt' => $finishedAt, 'finishedAt' => $finishedAt, 'durationMilliseconds' => max(0, ($finishedAt - $startedAt) * 1000)];
        }
        $actor = $this->actors->actorId();
        $now = $this->now();
        $id = substr(hash('sha256', implode("\0", [$actor, $resource, $path, $description['key']])), 0, 48);
        $dispatch = false;
        $job = $this->mutate(function (array $all) use ($id, $actor, $resource, $path, $description, $now, $retry, &$dispatch): array {
            $all = $this->expire($all);
            $current = is_array($all[$id] ?? null) ? $all[$id] : null;
            if ($current !== null && in_array($current['status'] ?? null, ['queued', 'running'], true)) return $all;
            if ($current !== null && in_array($current['status'] ?? null, ['failed', 'expired'], true) && !$retry) return $all;
            $dispatch = true;
            $all[$id] = ['id' => $id, 'actor' => $actor, 'resource' => $resource, 'path' => $path, 'key' => $description['key'], 'sourceType' => 'office', 'sourceFile' => null, 'targetFile' => $description['file'], 'status' => 'queued', 'error' => null, 'createdAt' => $now, 'startedAt' => null, 'finishedAt' => null, 'updatedAt' => $now, 'expiresAt' => $now + $this->jobTtlSeconds];
            return $all;
        })[$id];
        if ($dispatch) {
            try {
                $staged = $this->previews->stageOffice($resource, $path, $id);
                $now = $this->now();
                $this->mutate(static function (array $all) use ($id, $staged, $now): array { if (isset($all[$id])) { $all[$id]['sourceFile'] = $staged['source']; $all[$id]['targetFile'] = $staged['target']; $all[$id]['updatedAt'] = $now; } return $all; });
                $this->dispatch($id);
                $this->metrics?->increment('sofinder_document_preview_jobs_total', ['status' => 'queued']);
            } catch (\Throwable $exception) {
                $this->fail($id, $exception);
                throw $exception;
            }
            $job = $this->get($id);
        }
        return $this->publicJob($job);
    }

    /** @return array{id:string,status:string,retryAfter:int,error:array{code:string,message:string}|null,source:string,key:string,resource:string,path:string,mode:string,cached:bool,createdAt:int,startedAt:int|null,updatedAt:int,finishedAt:int|null,durationMilliseconds:int|null} */
    public function status(string $id): array
    {
        $job = $this->get($id);
        if (($job['actor'] ?? null) !== $this->actors->actorId()) throw new SoFinderException('The document preview job does not exist.', 'document_preview_job_not_found', 404);
        return $this->publicJob($job);
    }

    public function run(string $id): void
    {
        $now = $this->now();
        $claimed = false;
        $all = $this->mutate(function (array $all) use ($id, $now, &$claimed): array {
            $all = $this->expire($all);
            if (!isset($all[$id]) || !is_array($all[$id])) throw new SoFinderException('The document preview job does not exist.', 'document_preview_job_not_found', 404);
            if (($all[$id]['status'] ?? null) !== 'queued') return $all;
            $claimed = true;
            $all[$id]['status'] = 'running';
            $all[$id]['startedAt'] = $now;
            $all[$id]['updatedAt'] = $now;
            return $all;
        });
        if (!$claimed) return;
        $job = is_array($all[$id] ?? null) ? $all[$id] : [];
        try {
            $source = is_string($job['sourceFile'] ?? null) ? $job['sourceFile'] : '';
            $target = is_string($job['targetFile'] ?? null) ? $job['targetFile'] : '';
            if ($source === '' || $target === '') throw new SoFinderException('The document preview job source is unavailable.', 'document_preview_failed', 500);
            $this->previews->convertStaged($source, $target);
            $now = $this->now();
            $this->mutate(static function (array $all) use ($id, $now): array { if (isset($all[$id])) { $all[$id]['status'] = 'ready'; $all[$id]['error'] = null; $all[$id]['updatedAt'] = $now; $all[$id]['finishedAt'] = $now; } return $all; });
            $this->metrics?->increment('sofinder_document_preview_jobs_total', ['status' => 'ready']);
        } catch (\Throwable $exception) {
            $this->fail($id, $exception);
            throw $exception;
        }
    }

    public function asynchronous(): bool
    {
        return $this->mode === 'messenger' || ($this->mode === 'auto' && $this->hasBus());
    }

    /** @return array{configuredMode:string,effectiveMode:string,queueAvailable:bool,counts:array{queued:int,running:int,ready:int,failed:int,expired:int},lastSuccessfulAt:?int} */
    public function diagnostics(): array
    {
        $all = $this->mutate(fn (array $jobs): array => $this->expire($jobs));
        $counts = ['queued' => 0, 'running' => 0, 'ready' => 0, 'failed' => 0, 'expired' => 0];
        $lastSuccessfulAt = null;
        foreach ($all as $job) {
            if (!is_array($job)) continue;
            $status = $job['status'] ?? null;
            if (is_string($status) && isset($counts[$status])) ++$counts[$status];
            if ($status === 'ready') $lastSuccessfulAt = max($lastSuccessfulAt ?? 0, (int) ($job['finishedAt'] ?? $job['updatedAt'] ?? 0)) ?: $lastSuccessfulAt;
        }
        return ['configuredMode' => $this->mode, 'effectiveMode' => $this->asynchronous() ? 'messenger' : 'inline', 'queueAvailable' => $this->hasBus(), 'counts' => $counts, 'lastSuccessfulAt' => $lastSuccessfulAt];
    }

    public function cleanup(): int
    {
        $before = 0; $after = 0;
        $this->mutate(function (array $all) use (&$before, &$after): array { $before = count($all); $all = $this->expire($all); $after = count($all); return $all; });
        return $before - $after + $this->previews->cleanupExpired(time() - $this->cacheTtlSeconds);
    }

    /** @return array<string,mixed> */
    private function get(string $id): array
    {
        $all = $this->mutate(fn (array $all): array => $this->expire($all));
        $job = $all[$id] ?? null;
        if (!is_array($job)) throw new SoFinderException('The document preview job does not exist.', 'document_preview_job_not_found', 404);
        return $job;
    }

    private function dispatch(string $id): void
    {
        $bus = $this->bus;
        if ($bus === null || !method_exists($bus, 'dispatch')) throw new SoFinderException('The document preview queue is unavailable.', 'document_preview_queue_unavailable', 503);
        $bus->dispatch(new DocumentPreviewMessage($id));
    }

    private function hasBus(): bool { return $this->bus !== null && method_exists($this->bus, 'dispatch'); }

    private function fail(string $id, \Throwable $exception): void
    {
        $code = $exception instanceof SoFinderException ? $exception->errorCode : 'document_preview_failed';
        $now = $this->now();
        $this->mutate(static function (array $all) use ($id, $code, $now): array { if (isset($all[$id])) { $all[$id]['status'] = 'failed'; $all[$id]['error'] = ['code' => $code, 'message' => 'The Office document could not be prepared for preview.']; $all[$id]['updatedAt'] = $now; $all[$id]['finishedAt'] = $now; } return $all; });
        $this->metrics?->increment('sofinder_document_preview_jobs_total', ['status' => 'failed', 'code' => $code]);
    }

    /**
     * @param array<string,mixed> $all
     * @return array<string,mixed>
     */
    private function expire(array $all): array
    {
        $now = $this->now();
        foreach ($all as $id => &$job) {
            if (!is_array($job) || (int) ($job['purgeAt'] ?? PHP_INT_MAX) <= $now) { unset($all[$id]); continue; }
            if (($job['status'] ?? null) !== 'expired' && (int) ($job['expiresAt'] ?? 0) <= $now) {
                $job['status'] = 'expired';
                $job['error'] = ['code' => 'document_preview_expired', 'message' => 'The document preview job has expired.'];
                $job['updatedAt'] = $now;
                $job['purgeAt'] = $now + $this->jobTtlSeconds;
            }
        }
        unset($job);
        return array_slice($all, -1000, null, true);
    }

    /**
     * @param array<string,mixed> $job
     * @return array{id:string,status:string,retryAfter:int,error:array{code:string,message:string}|null,source:string,key:string,resource:string,path:string,mode:string,cached:bool,createdAt:int,startedAt:int|null,updatedAt:int,finishedAt:int|null,durationMilliseconds:int|null}
     */
    private function publicJob(array $job): array
    {
        $storedError = $job['error'] ?? null;
        $error = is_array($storedError) && is_string($storedError['code'] ?? null) && is_string($storedError['message'] ?? null)
            ? ['code' => $storedError['code'], 'message' => $storedError['message']]
            : null;
        $startedAt = is_int($job['startedAt'] ?? null) ? $job['startedAt'] : null;
        $finishedAt = is_int($job['finishedAt'] ?? null) ? $job['finishedAt'] : null;
        return ['id' => (string) ($job['id'] ?? ''), 'status' => (string) ($job['status'] ?? 'failed'), 'retryAfter' => in_array($job['status'] ?? null, ['queued', 'running'], true) ? 1 : 0, 'error' => $error, 'source' => (string) ($job['sourceType'] ?? 'office'), 'key' => (string) ($job['key'] ?? ''), 'resource' => (string) ($job['resource'] ?? ''), 'path' => (string) ($job['path'] ?? ''), 'mode' => 'messenger', 'cached' => false, 'createdAt' => (int) ($job['createdAt'] ?? 0), 'startedAt' => $startedAt, 'updatedAt' => (int) ($job['updatedAt'] ?? 0), 'finishedAt' => $finishedAt, 'durationMilliseconds' => $startedAt !== null && $finishedAt !== null ? max(0, ($finishedAt - $startedAt) * 1000) : null];
    }

    /**
     * @param callable(array<string,mixed>):array<string,mixed> $callback
     * @return array<string,mixed>
     */
    private function mutate(callable $callback): array
    {
        if ($this->state !== null) return $this->state->mutate('document-preview-jobs', 'global', $callback);
        $directory = dirname($this->stateFile);
        if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) throw new SoFinderException('Unable to store document preview jobs.', 'document_preview_failed', 500);
        $stream = @fopen($this->stateFile, 'c+b');
        if ($stream === false || !flock($stream, LOCK_EX)) throw new SoFinderException('Unable to store document preview jobs.', 'document_preview_failed', 500);
        try {
            $json = stream_get_contents($stream); $decoded = is_string($json) && $json !== '' ? json_decode($json, true) : []; $next = $callback(is_array($decoded) ? $decoded : []);
            rewind($stream); ftruncate($stream, 0); fwrite($stream, json_encode($next, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)); fflush($stream); return $next;
        } finally { flock($stream, LOCK_UN); fclose($stream); }
    }

    private function now(): int { return ($this->clock ?? static fn (): int => time())(); }
}

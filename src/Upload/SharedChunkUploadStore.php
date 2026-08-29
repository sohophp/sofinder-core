<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Upload;

use SohoPHP\SoFinder\Contract\ActorProviderInterface;
use SohoPHP\SoFinder\Contract\AtomicStateStoreInterface;
use SohoPHP\SoFinder\Contract\ChunkUploadStoreInterface;
use SohoPHP\SoFinder\Exception\SoFinderException;

/**
 * Coordinates chunk sessions through Redis/PDO while keeping bounded binary data
 * in the configured chunk directory. Every node must mount that directory at the
 * same path; the shared state provides the cross-node lock and resumable manifest.
 */
final readonly class SharedChunkUploadStore implements ChunkUploadStoreInterface
{
    public function __construct(
        private ChunkUploadManager $local,
        private AtomicStateStoreInterface $state,
        private ActorProviderInterface $actors,
    ) {
    }

    public function accept(string $id, int $index, int $total, mixed $stream, int $maximumFileBytes, array $context = []): array
    {
        $result = null;
        $key = $this->key($id);
        $this->state->mutate('chunk-session', $key, function (array $stored) use ($id, $index, $total, $stream, $maximumFileBytes, $context, &$result): array {
            if (($stored['discarded'] ?? false) === true) $stored = [];
            $result = $this->local->accept($id, $index, $total, $stream, $maximumFileBytes, $context);
            $status = $this->local->status($id);

            return $status + ['updatedAt' => time()];
        });
        $this->index($key);

        return is_array($result) ? $result : ['complete' => false];
    }

    public function status(string $id): array
    {
        $state = $this->state->get('chunk-session', $this->key($id));
        if (($state['discarded'] ?? false) === true || !isset($state['id'], $state['total'], $state['received'])) {
            throw new SoFinderException('The upload session does not exist or is invalid.', 'upload_session_not_found', 404);
        }

        /** @var array{id:string,total:int,received:list<int>,complete:bool,resource:string,path:string,name:string,overwrite:bool,autoRename?:bool,workspace?:string,updatedAt:int} $state */
        return $state + ['autoRename' => false, 'workspace' => ''];
    }

    public function discard(string $id): void
    {
        $key = $this->key($id);
        $this->state->mutate('chunk-session', $key, function (array $state) use ($id): array {
            $this->local->discard($id);

            return ['discarded' => true, 'updatedAt' => time()];
        });
    }

    public function cleanupExpired(bool $allActors = false, ?int $limit = null): int
    {
        $purged = $this->local->cleanupExpired($allActors, $limit);
        $cutoff = time() - 86_400;
        $remaining = $limit;
        $index = $this->state->get('chunk-index', 'global');
        $keys = is_array($index['keys'] ?? null) ? $index['keys'] : [];
        $expired = [];
        $actorPrefix = hash('sha256', $this->actors->actorId()) . ':';
        foreach ($keys as $key) {
            if (!is_string($key) || (!$allActors && !str_starts_with($key, $actorPrefix))) continue;
            $session = $this->state->get('chunk-session', $key);
            if ((int) ($session['updatedAt'] ?? 0) >= $cutoff || ($remaining !== null && $remaining < 1)) continue;
            $this->state->mutate('chunk-session', $key, static fn (array $state): array => ['discarded' => true, 'updatedAt' => time()]);
            $expired[] = $key;
            if ($remaining !== null) --$remaining;
        }
        if ($expired !== []) {
            $this->state->mutate('chunk-index', 'global', static function (array $index) use ($expired): array {
                $current = is_array($index['keys'] ?? null) ? $index['keys'] : [];

                return ['keys' => array_values(array_diff(array_filter($current, 'is_string'), $expired))];
            });
        }

        return $purged;
    }

    private function key(string $id): string
    {
        return hash('sha256', $this->actors->actorId()) . ':' . $id;
    }

    private function index(string $key): void
    {
        $this->state->mutate('chunk-index', 'global', static function (array $index) use ($key): array {
            $keys = is_array($index['keys'] ?? null) ? $index['keys'] : [];
            $keys[] = $key;

            return ['keys' => array_slice(array_values(array_unique(array_filter($keys, 'is_string'))), -10000)];
        });
    }
}

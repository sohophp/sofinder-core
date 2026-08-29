<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder;

use Psr\EventDispatcher\EventDispatcherInterface;
use SohoPHP\SoFinder\Contract\AuthorizationInterface;
use SohoPHP\SoFinder\Contract\EntryUrlGeneratorInterface;
use SohoPHP\SoFinder\Contract\RecycleBinInterface;
use SohoPHP\SoFinder\Contract\UsageTrackerInterface;
use SohoPHP\SoFinder\Exception\AccessDeniedException;
use SohoPHP\SoFinder\Exception\ConflictException;
use SohoPHP\SoFinder\Exception\InvalidPathException;
use SohoPHP\SoFinder\Exception\SoFinderException;
use SohoPHP\SoFinder\Event\OperationEvent;
use SohoPHP\SoFinder\Security\PathGuard;
use SohoPHP\SoFinder\Security\DefaultFileInspector;
use SohoPHP\SoFinder\Security\UploadPipeline;
use SohoPHP\SoFinder\Storage\StoragePaginator;
use SohoPHP\SoFinder\Usage\PersistentUsageTracker;
use SohoPHP\SoFinder\Image\GdImageProcessor;
use SohoPHP\SoFinder\Maintenance\MaintenanceCoordinator;
use SohoPHP\SoFinder\Maintenance\MaintenanceTask;
use SohoPHP\SoFinder\Value\Entry;
use SohoPHP\SoFinder\Value\ListQuery;
use SohoPHP\SoFinder\Value\ResourceStorage;
use SohoPHP\SoFinder\Value\TrashItem;
use SohoPHP\SoFinder\Workspace\WorkspaceProvider;

final readonly class FileManager
{
    private UploadPipeline $uploads;
    private StoragePaginator $paginator;

    public function __construct(
        private ResourceRegistry $resources,
        private AuthorizationInterface $authorization,
        private EventDispatcherInterface $events,
        private PathGuard $pathGuard = new PathGuard(),
        ?UploadPipeline $uploads = null,
        private ?EntryUrlGeneratorInterface $entryUrls = null,
        private ?RecycleBinInterface $trash = null,
        ?UsageTrackerInterface $usage = null,
        ?StoragePaginator $paginator = null,
        private ?MaintenanceCoordinator $maintenance = null,
        private ?WorkspaceProvider $workspaces = null,
    ) {
        $this->uploads = $uploads ?? new UploadPipeline(
            new DefaultFileInspector(new GdImageProcessor()),
            sys_get_temp_dir() . '/sofinder-quarantine',
        );
        $this->usage = $usage ?? new PersistentUsageTracker(sys_get_temp_dir() . '/sofinder-usage');
        $this->paginator = $paginator ?? new StoragePaginator();
    }

    private UsageTrackerInterface $usage;

    /** @return list<array<string, mixed>> */
    public function resources(): array
    {
        if (!$this->authorization->isAuthenticated()) {
            throw new AccessDeniedException('Authentication is required.');
        }

        $visible = array_filter(
            $this->resources->all(),
            fn (ResourceStorage $item): bool => $this->authorization->isGranted('list', $item->resource, ''),
        );

        return array_values(array_map(
            fn (ResourceStorage $item): array => $item->resource->jsonSerialize() + [
                'usedBytes' => $this->usage->usage($item),
                'storageCapabilities' => $item->storage->capabilities(),
            ],
            $visible,
        ));
    }

    /**
     * @param list<string>|null $onlyPaths
     * @return array{entries:list<Entry>,total:?int,path:string,offset:int,limit:int,sort:string,direction:string,nextCursor:?string,capabilities:array<string,bool>,storageCapabilities:\SohoPHP\SoFinder\Value\StorageCapabilities}
     */
    public function list(
        string $resourceName,
        string $path = '',
        string $search = '',
        string $sort = 'name',
        string $direction = 'asc',
        int $offset = 0,
        int $limit = 100,
        ?array $onlyPaths = null,
        ?string $cursor = null,
    ): array
    {
        $item = $this->authorized($resourceName, 'list', $path);
        $storageCapabilities = $item->storage->capabilities();
        if (trim($search) !== '' && !$storageCapabilities->search) {
            throw new SoFinderException('This storage adapter does not support server-side search.', 'storage_search_unsupported', 422);
        }
        if ($sort !== 'name' && !$storageCapabilities->sort) {
            throw new SoFinderException('This storage adapter does not support the requested sorting mode.', 'storage_sort_unsupported', 422);
        }
        $query = new ListQuery(
            $this->pathGuard->normalize($path),
            $search,
            $sort,
            $direction,
            $offset,
            $limit,
            $cursor,
            $onlyPaths,
            fn (Entry $entry): bool => $this->authorization->isGranted(
                $entry->directory ? 'list' : 'read',
                $item->resource,
                $entry->path,
            ),
        );
        $page = $item->storage->list($query);
        $entries = $page->entries;
        $sort = $query->sort;
        $direction = $query->direction;
        $limit = $page->limit;
        $total = $page->total;
        $offset = $page->offset;

        return [
            'entries' => array_map(fn (Entry $entry): Entry => $this->present($item, $entry), $entries),
            'total' => $total,
            'path' => $this->pathGuard->normalize($path),
            'offset' => $offset,
            'limit' => $limit,
            'sort' => $sort,
            'direction' => $direction,
            'nextCursor' => $page->nextCursor,
            'capabilities' => $this->capabilities($item, $this->pathGuard->normalize($path)),
            'storageCapabilities' => $storageCapabilities,
        ];
    }

    public function createFolder(string $resourceName, string $directory, string $name): Entry
    {
        $path = $this->pathGuard->join($directory, trim($name));
        $item = $this->authorized($resourceName, 'create_folder', $path, true);
        $item->resource->assertEntryPathAllowed($path, true);
        $this->before('create_folder', $item, $path);
        $entry = $item->storage->createDirectory($path);
        $this->after('create_folder', $item, $path, ['entry' => $entry]);

        return $this->present($item, $entry);
    }

    /** @param resource $stream */
    public function upload(
        string $resourceName,
        string $directory,
        string $name,
        int $size,
        mixed $stream,
        bool $overwrite = false,
        bool $autoRename = false,
    ): Entry
    {
        $path = $this->pathGuard->join($directory, trim($name));
        $authorizationOperation = $overwrite ? 'overwrite' : 'upload';
        $item = $this->authorized($resourceName, $authorizationOperation, $path, true);
        $item->resource->assertFileNameAllowed($name);
        $item->resource->assertEntryPathAllowed($path, false);
        $quarantined = $this->uploads->quarantine($stream, $name, $item->resource);
        $actualSize = $quarantined['inspection']->size;
        $input = null;
        try {
            $input = fopen($quarantined['path'], 'rb');
            if ($input === false) {
                throw new SoFinderException('Unable to read the inspected upload.', 'upload_quarantine_failed', 500);
            }

            return $this->usage->mutate($item, function (int $currentUsage) use ($item, $path, $input, $overwrite, $autoRename, $actualSize, $size, $authorizationOperation): array {
                $replacedBytes = $overwrite ? $this->existingSize($item, $path) : 0;
                $this->assertQuota($item, $currentUsage, $replacedBytes, $actualSize);

                $attempts = $autoRename && !$overwrite ? 1000 : 1;
                for ($attempt = 0; $attempt < $attempts; ++$attempt) {
                    $destination = $autoRename && !$overwrite
                        ? $this->availableName($item, $path, true)
                        : $path;
                    $destinationName = basename($destination);
                    $item->resource->assertFileNameAllowed($destinationName);
                    $item->resource->assertEntryPathAllowed($destination, false);
                    $this->assertGranted($item, $authorizationOperation, $destination);
                    $this->before($authorizationOperation, $item, $destination, ['size' => $actualSize, 'reported_size' => $size]);
                    try {
                        $entry = $item->storage->writeStream($destination, $input, $overwrite);
                    } catch (ConflictException $exception) {
                        if (!$autoRename || $overwrite || $attempt === $attempts - 1) {
                            throw $exception;
                        }
                        if (fseek($input, 0) !== 0) {
                            throw new SoFinderException('Unable to retry the inspected upload.', 'upload_quarantine_failed', 500);
                        }

                        continue;
                    }
                    $this->after($authorizationOperation, $item, $destination, ['entry' => $entry, 'size' => $actualSize]);

                    return ['value' => $this->present($item, $entry), 'delta' => $actualSize - $replacedBytes];
                }

                throw new ConflictException('Unable to find an available upload name.');
            });
        } finally {
            if (is_resource($input)) {
                fclose($input);
            }
            @unlink($quarantined['path']);
        }
    }

    public function uploadLimit(string $resourceName, string $directory, string $name): int
    {
        $path = $this->pathGuard->join($directory, trim($name));
        $item = $this->authorized($resourceName, 'upload', $path, true);
        $item->resource->assertFileNameAllowed($name);
        $item->resource->assertEntryPathAllowed($path, false);

        return $item->resource->maxSize;
    }

    /** @return array{maxSelection:int,maxItems:int,maxBytes:int,maxRecursiveItems:int} */
    public function archiveLimits(string $resourceName, string $path): array
    {
        $item = $this->authorized($resourceName, 'read', $path);

        return [
            'maxSelection' => $item->resource->maxBatchItems,
            'maxItems' => $item->resource->maxArchiveItems,
            'maxBytes' => $item->resource->maxArchiveBytes,
            'maxRecursiveItems' => $item->resource->maxRecursiveItems,
        ];
    }

    public function rename(string $resourceName, string $path, string $newName, bool $overwrite = false): Entry
    {
        $path = $this->pathGuard->normalize($path);
        $directory = dirname($path);
        $item = $this->authorized($resourceName, 'rename', $path, true);
        $source = $item->storage->entry($path);
        $newName = trim($newName);
        if (!$source->directory) {
            $sourceExtension = (string) pathinfo($source->name, PATHINFO_EXTENSION);
            $destinationExtension = (string) pathinfo($newName, PATHINFO_EXTENSION);
            if ($sourceExtension !== $destinationExtension) {
                throw new SoFinderException(
                    'A file extension cannot be changed or removed during rename.',
                    'extension_change_not_allowed',
                    422,
                );
            }
            $item->resource->assertFileNameAllowed($newName);
        }
        $destination = $this->pathGuard->join($directory === '.' ? '' : $directory, $newName);
        $item->resource->assertEntryPathAllowed(
            $destination,
            $source->directory,
            $source->directory ? $this->maximumDescendantDepth($item, $path, 'rename') : 0,
        );
        $this->assertGranted($item, 'rename', $destination);
        if ($overwrite) {
            $this->assertGranted($item, 'overwrite', $destination);
        }
        $entry = $this->usage->mutate($item, function () use ($item, $path, $destination, $overwrite): array {
            $replacedBytes = $overwrite && $destination !== $path ? $this->existingSize($item, $destination) : 0;
            $this->before('rename', $item, $path, ['destination' => $destination]);
            $entry = $item->storage->move($path, $destination, $overwrite);
            $this->after('rename', $item, $destination, ['source' => $path, 'entry' => $entry]);

            return ['value' => $entry, 'delta' => -$replacedBytes];
        });

        return $this->present($item, $entry);
    }

    public function transfer(string $operation, string $resourceName, string $path, string $destinationDirectory, bool $overwrite = false, bool $autoRename = false): Entry
    {
        if (!in_array($operation, ['copy', 'move'], true)) {
            throw new \InvalidArgumentException('Transfer operation must be copy or move.');
        }
        $path = $this->pathGuard->normalize($path);
        $destination = $this->pathGuard->join($destinationDirectory, basename($path));
        $item = $this->authorized($resourceName, $operation, $path, true);
        $source = $item->storage->entry($path);
        if (!$source->directory) {
            $item->resource->assertFileNameAllowed(basename($destination));
        }
        $additionalFolderDepth = $source->directory ? $this->maximumDescendantDepth($item, $path, $operation) : 0;
        if ($destination === $path || ($source->directory && str_starts_with($destination, $path . '/'))) {
            throw new InvalidPathException('An entry cannot be copied or moved into itself.');
        }
        $item->resource->assertEntryPathAllowed(
            $destination,
            $source->directory,
            $additionalFolderDepth,
        );
        $this->assertGranted($item, $operation, $destination);
        if ($autoRename) {
            $destination = $this->availableName($item, $destination);
            if (!$source->directory) {
                $item->resource->assertFileNameAllowed(basename($destination));
            }
            $item->resource->assertEntryPathAllowed(
                $destination,
                $source->directory,
                $additionalFolderDepth,
            );
        } elseif ($overwrite) {
            $this->assertGranted($item, 'overwrite', $destination);
        }
        $entry = $this->usage->mutate($item, function (int $currentUsage) use ($operation, $item, $path, $destination, $overwrite): array {
            $sourceBytes = $item->storage->size($path);
            $replacedBytes = $overwrite ? $this->existingSize($item, $destination) : 0;
            if ($operation === 'copy') {
                $this->assertQuota($item, $currentUsage, $replacedBytes, $sourceBytes);
            }
            $this->before($operation, $item, $path, ['destination' => $destination]);
            $entry = $operation === 'copy'
                ? $item->storage->copy($path, $destination, $overwrite)
                : $item->storage->move($path, $destination, $overwrite);
            $this->after($operation, $item, $destination, ['source' => $path, 'entry' => $entry]);

            return [
                'value' => $entry,
                'delta' => $operation === 'copy' ? $sourceBytes - $replacedBytes : -$replacedBytes,
            ];
        });

        return $this->present($item, $entry);
    }

    /**
     * @param list<string> $paths
     * @return array{operation:string,total:int,succeeded:int,failed:int,results:list<array{path:string,success:bool,entry?:Entry,error?:array{code:string,message:string}}>}
     */
    public function batch(
        string $operation,
        string $resourceName,
        array $paths,
        string $destinationDirectory = '',
        bool $overwrite = false,
        bool $autoRename = true,
    ): array {
        if (!in_array($operation, ['copy', 'move', 'delete'], true)) {
            throw new SoFinderException('The batch operation is invalid.', 'invalid_batch_operation', 400);
        }
        if ($paths === []) {
            throw new SoFinderException('At least one entry must be selected.', 'empty_batch', 400);
        }
        $limitItem = $this->authorized($resourceName, $operation, (string) $paths[0], true);
        if (count($paths) > $limitItem->resource->maxBatchItems) {
            throw new SoFinderException(sprintf('A batch can contain at most %d entries.', $limitItem->resource->maxBatchItems), 'batch_limit_exceeded', 413);
        }

        $normalized = [];
        foreach ($paths as $path) {
            $path = $this->pathGuard->normalize($path);
            if ($path === '') {
                throw new InvalidPathException('The storage root cannot be included in a batch.');
            }
            $normalized[$path] = $path;
        }
        $paths = array_values($normalized);
        foreach ($paths as $path) {
            foreach ($paths as $candidate) {
                if ($path !== $candidate && str_starts_with($candidate, $path . '/')) {
                    throw new SoFinderException(
                        'A batch cannot contain both a folder and one of its descendants.',
                        'overlapping_batch_paths',
                        422,
                    );
                }
            }
        }

        $results = [];
        $succeeded = 0;
        foreach ($paths as $path) {
            try {
                if ($operation === 'delete') {
                    $trash = $this->delete($resourceName, $path);
                    $results[] = ['path' => $path, 'success' => true, 'trash' => $trash];
                } else {
                    $entry = $this->transfer($operation, $resourceName, $path, $destinationDirectory, $overwrite, $autoRename);
                    $results[] = ['path' => $path, 'success' => true, 'entry' => $entry];
                }
                ++$succeeded;
            } catch (SoFinderException $exception) {
                $results[] = [
                    'path' => $path,
                    'success' => false,
                    'error' => ['code' => $exception->errorCode, 'message' => $exception->getMessage()],
                ];
            }
        }

        $purgedItems = array_sum(array_map(static fn (array $result): int => (int) ($result['trash']['purgedItems'] ?? 0), $results));
        $purgedBytes = array_sum(array_map(static fn (array $result): int => (int) ($result['trash']['purgedBytes'] ?? 0), $results));

        return [
            'operation' => $operation,
            'total' => count($paths),
            'succeeded' => $succeeded,
            'failed' => count($paths) - $succeeded,
            'results' => $results,
            'purgedItems' => $purgedItems,
            'purgedBytes' => $purgedBytes,
        ];
    }

    /**
     * @param list<array{path:string,name:string}> $renames
     * @return array{operation:string,total:int,succeeded:int,failed:int,results:list<array{path:string,success:bool,entry?:Entry,error?:array{code:string,message:string}}>}
     */
    public function batchRename(string $resourceName, array $renames): array
    {
        if ($renames === []) throw new SoFinderException('At least one entry must be selected.', 'empty_batch', 400);
        $first = $renames[0];
        $limitItem = $this->authorized($resourceName, 'rename', $first['path'], true);
        if (count($renames) > $limitItem->resource->maxBatchItems) {
            throw new SoFinderException(sprintf('A batch can contain at most %d entries.', $limitItem->resource->maxBatchItems), 'batch_limit_exceeded', 413);
        }
        $normalized = [];
        $destinations = [];
        foreach ($renames as $rename) {
            $path = $this->pathGuard->normalize($rename['path']);
            $name = trim($rename['name']);
            if ($path === '') throw new InvalidPathException('The storage root cannot be renamed.');
            $this->pathGuard->assertName($name);
            $destination = (dirname($path) === '.' ? '' : dirname($path) . '/') . $name;
            if (isset($normalized[$path]) || isset($destinations[$destination])) {
                throw new SoFinderException('Batch rename paths and destinations must be unique.', 'duplicate_batch_destination', 422);
            }
            $normalized[$path] = ['path' => $path, 'name' => $name];
            $destinations[$destination] = true;
        }
        $results = [];
        $succeeded = 0;
        foreach ($normalized as $rename) {
            try {
                $entry = $this->rename($resourceName, $rename['path'], $rename['name']);
                $results[] = ['path' => $rename['path'], 'success' => true, 'entry' => $entry];
                ++$succeeded;
            } catch (SoFinderException $exception) {
                $results[] = ['path' => $rename['path'], 'success' => false, 'error' => ['code' => $exception->errorCode, 'message' => $exception->getMessage()]];
            }
        }

        return ['operation' => 'rename', 'total' => count($normalized), 'succeeded' => $succeeded, 'failed' => count($normalized) - $succeeded, 'results' => $results];
    }

    /** @return array{item:TrashItem,purgedItems:int,purgedBytes:int}|null */
    public function delete(string $resourceName, string $path): ?array
    {
        $item = $this->authorized($resourceName, 'delete', $path, true);
        $trashed = $this->usage->mutate($item, function () use ($item, $path): array {
            $removedBytes = $item->storage->size($path);
            $this->before('delete', $item, $path);
            $trashed = $item->storage->capabilities()->recoverableDelete ? $this->trash?->put($item, $path) : null;
            if ($trashed === null) {
                $item->storage->delete($path);
            }
            $this->after('delete', $item, $path, ['trash' => $trashed]);

            return ['value' => $trashed, 'delta' => -$removedBytes];
        });
        if ($trashed !== null) {
            $this->maintenance?->trigger(MaintenanceTask::Trash);
        }

        return $trashed;
    }

    /** @return array{items:list<TrashItem>,total:int,offset:int,limit:int,usedItems:int,usedBytes:int,maxItems:int,maxBytes:int} */
    public function trash(string $resourceName, int $offset = 0, int $limit = 50, string $search = ''): array
    {
        if ($this->trash === null) {
            return ['items' => [], 'total' => 0, 'offset' => 0, 'limit' => 50, 'usedItems' => 0, 'usedBytes' => 0, 'maxItems' => 0, 'maxBytes' => 0];
        }
        $item = $this->authorized($resourceName, 'trash_list', '');
        $items = array_values(array_filter(
            $this->trash->list($resourceName),
            fn (TrashItem $trashed): bool => $this->authorization->isGranted('trash_list', $item->resource, $trashed->path),
        ));
        $search = trim($search);
        if ($search !== '') {
            $items = array_values(array_filter($items, static fn (TrashItem $trashed): bool => mb_stripos($trashed->path, $search) !== false));
        }
        $limit = max(10, min($limit, 100));
        $total = count($items);
        $maximumOffset = $total === 0 ? 0 : intdiv($total - 1, $limit) * $limit;
        $offset = max(0, min($offset, $maximumOffset));

        return [
            'items' => array_slice($items, $offset, $limit),
            'total' => $total,
            'offset' => $offset,
            'limit' => $limit,
            ...$this->trash->statistics(),
        ];
    }

    public function restoreTrash(string $resourceName, string $id, string $conflict = 'cancel'): Entry
    {
        if ($this->trash === null) {
            throw new SoFinderException('The recycle bin is disabled.', 'trash_disabled', 404);
        }
        $trash = $this->trash;
        $trashed = $trash->get($id);
        if ($trashed->resource !== $resourceName) {
            throw new \SohoPHP\SoFinder\Exception\NotFoundException();
        }
        $item = $this->authorized($resourceName, 'trash_restore', $trashed->path, true);
        if ($conflict === 'overwrite') {
            $this->assertGranted($item, 'overwrite', $trashed->path);
        }
        $entry = $this->usage->mutate($item, function (int $currentUsage) use ($item, $id, $conflict, $trashed, $trash): array {
            $replacedBytes = $conflict === 'overwrite' ? $this->existingSize($item, $trashed->path) : 0;
            $this->assertQuota($item, $currentUsage, $replacedBytes, $trashed->size);
            $entry = $trash->restore($item, $id, $conflict);
            $this->after('trash_restore', $item, $entry->path, ['entry' => $entry, 'trash_id' => $id]);

            return ['value' => $entry, 'delta' => $trashed->size - $replacedBytes];
        });

        return $this->present($item, $entry);
    }

    public function permanentlyDeleteTrash(string $resourceName, string $id): void
    {
        if ($this->trash === null) {
            throw new SoFinderException('The recycle bin is disabled.', 'trash_disabled', 404);
        }
        $trashed = $this->trash->get($id);
        if ($trashed->resource !== $resourceName) {
            throw new \SohoPHP\SoFinder\Exception\NotFoundException();
        }
        $item = $this->authorized($resourceName, 'trash_delete', $trashed->path, true);
        $this->trash->permanentlyDelete($id);
        $this->after('trash_delete', $item, $trashed->path, ['trash_id' => $id]);
    }

    public function entry(string $resourceName, string $path): Entry
    {
        $item = $this->authorized($resourceName, 'read', $path);

        return $this->present($item, $item->storage->entry($path));
    }

    /**
     * Assert a public operation capability without exposing the underlying storage.
     *
     * Controllers for optional platform features (for example asset metadata)
     * must use the same authorization and read-only checks as core file writes.
     */
    public function assertOperation(string $resourceName, string $operation, string $path, bool $write = false): void
    {
        $this->authorized($resourceName, $operation, $path, $write);
    }

    /** @return resource */
    public function read(string $resourceName, string $path): mixed
    {
        $item = $this->authorized($resourceName, 'read', $path);

        return $item->storage->readStream($path);
    }

    private function authorized(string $name, string $operation, string $path, bool $write = false): ResourceStorage
    {
        $this->workspaces?->assertResource($name);
        if (!$this->authorization->isAuthenticated()) {
            throw new AccessDeniedException('Authentication is required.');
        }
        $item = $this->resources->get($name);
        if ($write && $item->resource->readOnly) {
            throw new AccessDeniedException('This resource is read-only.');
        }
        $this->assertGranted($item, $operation, $path);

        return $item;
    }

    private function present(ResourceStorage $item, Entry $entry): Entry
    {
        $url = $this->entryUrls === null
            ? ($item->resource->deliveryMode === 'public' ? $entry->url : null)
            : $this->entryUrls->generate($item->resource, $entry);

        return new Entry(
            $entry->path,
            $entry->name,
            $entry->directory,
            $entry->size,
            $entry->modifiedAt,
            $entry->mimeType,
            $url,
            $this->capabilities($item, $entry->path),
        );
    }

    /** @return array<string, bool> */
    private function capabilities(ResourceStorage $item, string $path): array
    {
        $operations = ['read', 'list', 'upload', 'overwrite', 'create_folder', 'rename', 'copy', 'move', 'delete', 'metadata.update'];
        $capabilities = [];
        foreach ($operations as $operation) {
            $write = in_array($operation, ['upload', 'overwrite', 'create_folder', 'rename', 'copy', 'move', 'delete', 'metadata.update'], true);
            $capabilities[$operation] = (!$write || !$item->resource->readOnly)
                && $this->authorization->isGranted($operation, $item->resource, $path);
        }

        return $capabilities;
    }

    private function assertGranted(ResourceStorage $item, string $operation, string $path): void
    {
        if (!$this->authorization->isGranted($operation, $item->resource, $this->pathGuard->normalize($path))) {
            throw new AccessDeniedException();
        }
    }

    /** @param array<string, mixed> $context */
    private function before(string $operation, ResourceStorage $item, string $path, array $context = []): void
    {
        $this->events->dispatch(new OperationEvent('before.' . $operation, $item->resource, $path, $context));
    }

    /** @param array<string, mixed> $context */
    private function after(string $operation, ResourceStorage $item, string $path, array $context = []): void
    {
        $this->events->dispatch(new OperationEvent('after.' . $operation, $item->resource, $path, $context));
    }

    private function availableName(ResourceStorage $item, string $destination, bool $compactSuffix = false): string
    {
        try {
            $item->storage->entry($destination);
        } catch (\SohoPHP\SoFinder\Exception\NotFoundException) {
            return $destination;
        }

        $directory = dirname($destination);
        $directory = $directory === '.' ? '' : $directory;
        $name = basename($destination);
        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $stem = $extension === '' ? $name : substr($name, 0, -(strlen($extension) + 1));
        for ($index = 1; $index <= 999; ++$index) {
            $candidateName = $stem . ($compactSuffix ? '(' : ' (') . $index . ')' . ($extension === '' ? '' : '.' . $extension);
            $candidate = $this->pathGuard->join($directory, $candidateName);
            try {
                $item->storage->entry($candidate);
            } catch (\SohoPHP\SoFinder\Exception\NotFoundException) {
                return $candidate;
            }
        }

        throw new ConflictException('Unable to find an available destination name.');
    }

    private function maximumDescendantDepth(ResourceStorage $item, string $directory, string $operation): int
    {
        $maximum = 0;
        $count = 0;
        $pending = [[$directory, 0]];
        while ($pending !== []) {
            $candidate = array_pop($pending);
            if (!is_array($candidate) || !isset($candidate[0], $candidate[1]) || !is_string($candidate[0]) || !is_int($candidate[1])) {
                throw new \LogicException('The recursive directory queue is invalid.');
            }
            [$current, $depth] = $candidate;
            foreach ($this->paginator->all($item->storage, new ListQuery($current, limit: 500)) as $entry) {
                ++$count;
                if ($count > $item->resource->maxRecursiveItems) {
                    throw new SoFinderException('The recursive operation contains too many entries.', 'recursive_limit_exceeded', 413);
                }
                $this->assertGranted($item, $operation, $entry->path);
                if ($entry->directory) {
                    $nextDepth = $depth + 1;
                    $maximum = max($maximum, $nextDepth);
                    $pending[] = [$entry->path, $nextDepth];
                }
            }
        }

        return $maximum;
    }

    private function assertQuota(ResourceStorage $item, int $currentUsage, int $replacedBytes, int $incomingBytes): void
    {
        $quota = $item->resource->quotaBytes;
        if ($quota <= 0) {
            return;
        }
        if ($currentUsage - $replacedBytes + $incomingBytes > $quota) {
            throw new SoFinderException('The resource storage quota would be exceeded.', 'quota_exceeded', 413);
        }
    }

    private function existingSize(ResourceStorage $item, string $path): int
    {
        try {
            return $item->storage->size($path);
        } catch (\SohoPHP\SoFinder\Exception\NotFoundException) {
            return 0;
        }
    }
}

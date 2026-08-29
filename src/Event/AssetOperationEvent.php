<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Event;

use SohoPHP\SoFinder\Value\ResourceType;
use SohoPHP\SoFinder\Value\WorkspaceContext;

final readonly class AssetOperationEvent implements \JsonSerializable
{
    public const SCHEMA_VERSION = '1.0';
    public const OPERATIONS = ['upload', 'overwrite', 'rename', 'copy', 'move', 'delete', 'restore', 'image.process', 'metadata.update'];

    /** @param array<string,mixed> $attributes */
    public function __construct(
        public string $operationId,
        public string $operation,
        public string $phase,
        public WorkspaceContext $workspace,
        public ResourceType $resource,
        public string $path,
        public ?string $sourcePath,
        public ?string $assetId,
        public array $attributes = [],
    ) {
        if (preg_match('/^[a-f0-9]{32}$/D', $operationId) !== 1) throw new \InvalidArgumentException('Asset event operation ID is invalid.');
        if (!in_array($operation, self::OPERATIONS, true)) throw new \InvalidArgumentException('Asset event operation is invalid.');
        if (!in_array($phase, ['before', 'after', 'failed'], true)) throw new \InvalidArgumentException('Asset event phase is invalid.');
        if (count($attributes) > 32) throw new \InvalidArgumentException('Asset event attributes exceed the safe limit.');
        foreach ($attributes as $value) {
            if (!is_null($value) && !is_scalar($value)) throw new \InvalidArgumentException('Asset event attributes must contain JSON scalar values only.');
        }
    }

    /** @return array{schemaVersion:string,operationId:string,operation:string,phase:string,workspace:string,resource:string,path:string,sourcePath:?string,assetId:?string,attributes:array<string,mixed>} */
    public function jsonSerialize(): array
    {
        return [
            'schemaVersion' => self::SCHEMA_VERSION,
            'operationId' => $this->operationId,
            'operation' => $this->operation,
            'phase' => $this->phase,
            'workspace' => $this->workspace->id,
            'resource' => $this->resource->name,
            'path' => $this->path,
            'sourcePath' => $this->sourcePath,
            'assetId' => $this->assetId,
            'attributes' => $this->attributes,
        ];
    }
}

<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Value;

final readonly class WorkspaceContext implements \JsonSerializable
{
    /** @param list<string> $resources */
    public function __construct(public string $id, public string $actor, public array $resources = [])
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D', $id) !== 1) {
            throw new \InvalidArgumentException('Workspace IDs must be safe identifiers of at most 64 characters.');
        }
    }

    /** @return array{id:string,resources:list<string>} */
    public function jsonSerialize(): array
    {
        return ['id' => $this->id, 'resources' => $this->resources];
    }
}

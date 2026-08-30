<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Asset;

use SohoPHP\SoFinder\Contract\AssetAccessSessionStoreInterface;
use SohoPHP\SoFinder\Contract\AssetCatalogInterface;
use SohoPHP\SoFinder\Exception\SoFinderException;
use SohoPHP\SoFinder\FileManager;
use SohoPHP\SoFinder\ResourceRegistry;
use SohoPHP\SoFinder\Value\Entry;
use SohoPHP\SoFinder\Value\RequestContext;
use SohoPHP\SoFinder\Workspace\WorkspaceProvider;

final class AssetAccessSessionManager
{
    public function __construct(private readonly AssetCatalogInterface $catalog, private readonly AssetAccessSessionStoreInterface $store, private readonly WorkspaceProvider $workspaces, private readonly FileManager $files, private readonly ResourceRegistry $resources, private readonly bool $enabled, private readonly int $defaultTtl = 3600, private readonly int $maximumTtl = 86400, private readonly int $maximumAssets = 50, private readonly ?\Closure $clock = null) {}

    /**
     * @param list<string> $assetIds
     * @return array{id:string,token:string,expiresAt:int,assets:list<array{assetId:string,resource:string,path:string}>}
     */
    public function create(array $assetIds, ?int $ttl = null, ?RequestContext $context = null): array
    {
        $this->assertEnabled(); $assetIds = array_values(array_unique($assetIds)); if ($assetIds === [] || count($assetIds) > $this->maximumAssets) throw new SoFinderException('The asset access session size is invalid.', 'asset_access_session_size', 422);
        $lifetime = $ttl ?? $this->defaultTtl; if ($lifetime < 60 || $lifetime > $this->maximumTtl) throw new SoFinderException('The asset access session lifetime is invalid.', 'asset_access_session_ttl', 422);
        $workspace = $this->workspaces->current($context); $assets = [];
        foreach ($assetIds as $assetId) {
            if (preg_match('/^[a-f0-9-]{36}$/D', $assetId) !== 1) throw new SoFinderException('An asset ID is invalid.', 'asset_access_session_asset_invalid', 422);
            $record = $this->catalog->find($assetId); if ($record === null || $record->deleted || $record->workspace !== $workspace->id) throw new SoFinderException('An asset does not exist in this workspace.', 'asset_not_found', 404);
            $this->workspaces->assertResource($record->resource, $context); $resource = $this->resources->get($record->resource); if ($resource->resource->deliveryMode !== 'proxy') throw new SoFinderException('Access sessions only accept private proxy assets.', 'asset_access_session_public_asset', 422);
            $entry = $this->files->entry($record->resource, $record->path); if ($entry->directory) throw new SoFinderException('Folders cannot be added to an access session.', 'invalid_type', 400);
            $assets[] = ['assetId' => $record->id, 'resource' => $record->resource, 'path' => $record->path, 'size' => $entry->size, 'modifiedAt' => $entry->modifiedAt];
        }
        $id = bin2hex(random_bytes(16)); $secret = $this->encode(random_bytes(32)); $expiresAt = $this->now() + $lifetime;
        $this->store->put($id, ['secretHash' => hash('sha256', $secret), 'workspace' => $workspace->id, 'expiresAt' => $expiresAt, 'assets' => $assets]);
        return ['id' => $id, 'token' => $id . '.' . $secret, 'expiresAt' => $expiresAt, 'assets' => array_map(static fn (array $asset): array => ['assetId' => $asset['assetId'], 'resource' => $asset['resource'], 'path' => $asset['path']], $assets)];
    }

    /** @return array{resource:string,entry:Entry,stream:resource,expiresAt:int} */
    public function open(string $token, string $assetId): array
    {
        $this->assertEnabled(); [$id, $secret] = $this->token($token); $session = $this->store->get($id);
        if ($session === null || ($session['revoked'] ?? false) || !is_string($session['secretHash'] ?? null) || !hash_equals($session['secretHash'], hash('sha256', $secret))) throw new SoFinderException('The asset access session is invalid.', 'asset_access_session_invalid', 403);
        if ((int) ($session['expiresAt'] ?? 0) < $this->now()) { $this->store->remove($id); throw new SoFinderException('The asset access session has expired.', 'asset_access_session_expired', 410); }
        foreach (is_array($session['assets'] ?? null) ? $session['assets'] : [] as $asset) {
            if (!is_array($asset) || ($asset['assetId'] ?? null) !== $assetId) continue; $resource = (string) ($asset['resource'] ?? ''); $path = (string) ($asset['path'] ?? ''); $item = $this->resources->get($resource); $entry = $item->storage->entry($path);
            if ($entry->directory || $entry->size !== (int) ($asset['size'] ?? -1) || $entry->modifiedAt !== (int) ($asset['modifiedAt'] ?? -1)) throw new SoFinderException('The asset changed after the access session was issued.', 'asset_access_session_stale', 410);
            return ['resource' => $resource, 'entry' => $entry, 'stream' => $item->storage->readStream($path), 'expiresAt' => (int) $session['expiresAt']];
        }
        throw new SoFinderException('The asset is not part of this access session.', 'asset_access_session_asset_denied', 403);
    }

    public function revoke(string $id, ?RequestContext $context = null): void { $this->assertEnabled(); if (preg_match('/^[a-f0-9]{32}$/D', $id) !== 1) throw new SoFinderException('The asset access session is invalid.', 'asset_access_session_invalid', 404); $session = $this->store->get($id); if ($session === null || ($session['workspace'] ?? null) !== $this->workspaces->current($context)->id) throw new SoFinderException('The asset access session does not exist.', 'asset_access_session_invalid', 404); $this->store->remove($id); }
    /** @return array{string,string} */
    private function token(string $token): array { if (preg_match('/^([a-f0-9]{32})\.([A-Za-z0-9_-]{43})$/D', $token, $match) !== 1) throw new SoFinderException('The asset access session is invalid.', 'asset_access_session_invalid', 403); return [$match[1], $match[2]]; }
    private function encode(string $value): string { return rtrim(strtr(base64_encode($value), '+/', '-_'), '='); }
    private function now(): int { return $this->clock !== null ? (int) ($this->clock)() : time(); }
    private function assertEnabled(): void { if (!$this->enabled) throw new SoFinderException('Asset access sessions are disabled.', 'asset_access_sessions_disabled', 404); }
}

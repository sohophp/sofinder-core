<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Asset;

use SohoPHP\SoFinder\Contract\AssetUsageStoreInterface;
use SohoPHP\SoFinder\Contract\AtomicStateStoreInterface;

final readonly class SharedAssetUsageStore implements AssetUsageStoreInterface
{
    public function __construct(private AtomicStateStoreInterface $state)
    {
    }

    public function list(string $workspace, string $assetId): array
    {
        $data = $this->state->get('asset_usage', $this->key($workspace, $assetId)); $items = is_array($data['items'] ?? null) ? $data['items'] : []; $result = [];
        foreach ($items as $referenceId => $value) if (is_string($referenceId) && is_array($value)) $result[] = $this->usage($referenceId, $value);
        usort($result, static fn (array $left, array $right): int => $right['updatedAt'] <=> $left['updatedAt']); return $result;
    }

    public function put(string $workspace, string $assetId, string $referenceId, string $label, ?string $url, ?string $context): array
    {
        $value = ['label' => $label, 'url' => $url, 'context' => $context, 'updatedAt' => time()];
        $this->state->mutate('asset_usage', $this->key($workspace, $assetId), static function (array $data) use ($referenceId, $value): array { $data['items'] = is_array($data['items'] ?? null) ? $data['items'] : []; $data['items'][$referenceId] = $value; return $data; });
        return $this->usage($referenceId, $value);
    }

    public function remove(string $workspace, string $assetId, string $referenceId): void
    {
        $this->state->mutate('asset_usage', $this->key($workspace, $assetId), static function (array $data) use ($referenceId): array { if (is_array($data['items'] ?? null)) unset($data['items'][$referenceId]); return $data; });
    }

    /**
     * @param array<string,mixed> $value
     * @return array{referenceId:string,label:string,url:?string,context:?string,updatedAt:int}
     */
    private function usage(string $referenceId, array $value): array { return ['referenceId' => $referenceId, 'label' => (string) ($value['label'] ?? ''), 'url' => is_string($value['url'] ?? null) ? $value['url'] : null, 'context' => is_string($value['context'] ?? null) ? $value['context'] : null, 'updatedAt' => (int) ($value['updatedAt'] ?? 0)]; }
    private function key(string $workspace, string $assetId): string { return hash('sha256', $workspace . "\0" . $assetId); }
}

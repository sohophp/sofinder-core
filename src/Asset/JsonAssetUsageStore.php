<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Asset;

use SohoPHP\SoFinder\Contract\AssetUsageStoreInterface;
use SohoPHP\SoFinder\Exception\SoFinderException;

final class JsonAssetUsageStore implements AssetUsageStoreInterface
{
    public function __construct(private readonly string $file)
    {
    }

    public function list(string $workspace, string $assetId): array
    {
        $data = $this->read(); $items = $data[$this->key($workspace, $assetId)] ?? [];
        if (!is_array($items)) return [];
        $result = [];
        foreach ($items as $referenceId => $item) if (is_string($referenceId) && is_array($item)) $result[] = $this->usage($referenceId, $item);
        usort($result, static fn (array $left, array $right): int => $right['updatedAt'] <=> $left['updatedAt']);
        return $result;
    }

    public function put(string $workspace, string $assetId, string $referenceId, string $label, ?string $url, ?string $context): array
    {
        $value = ['label' => $label, 'url' => $url, 'context' => $context, 'updatedAt' => time()];
        $this->mutate(function (array &$data) use ($workspace, $assetId, $referenceId, $value): void { $data[$this->key($workspace, $assetId)][$referenceId] = $value; });
        return $this->usage($referenceId, $value);
    }

    public function remove(string $workspace, string $assetId, string $referenceId): void
    {
        $this->mutate(function (array &$data) use ($workspace, $assetId, $referenceId): void { unset($data[$this->key($workspace, $assetId)][$referenceId]); });
    }

    /** @return array<string,mixed> */
    private function read(): array
    {
        if (!is_file($this->file)) return [];
        $handle = fopen($this->file, 'rb'); if ($handle === false) return [];
        try { if (!flock($handle, LOCK_SH)) return []; $contents = stream_get_contents($handle); flock($handle, LOCK_UN); }
        finally { fclose($handle); }
        $value = json_decode(is_string($contents) ? $contents : '', true);
        return is_array($value) ? $value : [];
    }

    /** @param \Closure(array<string,mixed>&):void $change */
    private function mutate(\Closure $change): void
    {
        $directory = dirname($this->file); if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) throw new SoFinderException('Unable to create the asset usage directory.', 'asset_usage_store_failed', 500);
        $handle = fopen($this->file, 'c+b'); if ($handle === false) throw new SoFinderException('Unable to open the asset usage store.', 'asset_usage_store_failed', 500);
        try {
            if (!flock($handle, LOCK_EX)) throw new SoFinderException('Unable to lock the asset usage store.', 'asset_usage_store_failed', 500);
            $contents = stream_get_contents($handle); $data = json_decode(is_string($contents) ? $contents : '', true); if (!is_array($data)) $data = [];
            $change($data); $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            rewind($handle); if (!ftruncate($handle, 0) || fwrite($handle, $json) === false || !fflush($handle)) throw new SoFinderException('Unable to write the asset usage store.', 'asset_usage_store_failed', 500);
            flock($handle, LOCK_UN);
        } finally { fclose($handle); }
    }

    /**
     * @param array<string,mixed> $value
     * @return array{referenceId:string,label:string,url:?string,context:?string,updatedAt:int}
     */
    private function usage(string $referenceId, array $value): array { return ['referenceId' => $referenceId, 'label' => (string) ($value['label'] ?? ''), 'url' => is_string($value['url'] ?? null) ? $value['url'] : null, 'context' => is_string($value['context'] ?? null) ? $value['context'] : null, 'updatedAt' => (int) ($value['updatedAt'] ?? 0)]; }
    private function key(string $workspace, string $assetId): string { return hash('sha256', $workspace . "\0" . $assetId); }
}

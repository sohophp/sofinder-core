<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Asset;

use SohoPHP\SoFinder\Contract\AssetAccessSessionStoreInterface;
use SohoPHP\SoFinder\Exception\SoFinderException;

final readonly class JsonAssetAccessSessionStore implements AssetAccessSessionStoreInterface
{
    public function __construct(private string $directory)
    {
    }
    public function put(string $id, array $session): void { $this->ensure(); $json = json_encode($session, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES); if (file_put_contents($this->file($id), $json, LOCK_EX) === false) throw new SoFinderException('Unable to save the asset access session.', 'asset_access_session_failed', 500); }
    public function get(string $id): ?array { $file = $this->file($id); if (!is_file($file)) return null; $value = json_decode((string) file_get_contents($file), true); return is_array($value) ? $value : null; }
    public function remove(string $id): void { $file = $this->file($id); if (is_file($file)) @unlink($file); }
    private function ensure(): void { if (!is_dir($this->directory) && !mkdir($this->directory, 0775, true) && !is_dir($this->directory)) throw new SoFinderException('Unable to create the asset access session directory.', 'asset_access_session_failed', 500); }
    private function file(string $id): string { return rtrim($this->directory, '/') . '/' . hash('sha256', $id) . '.json'; }
}

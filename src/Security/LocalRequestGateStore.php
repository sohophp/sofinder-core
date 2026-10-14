<?php
declare(strict_types=1);
namespace SohoPHP\SoFinder\Security;
use SohoPHP\SoFinder\Contract\RequestGateStoreInterface;
use SohoPHP\SoFinder\Exception\SoFinderException;
final class LocalRequestGateStore implements RequestGateStoreInterface
{
    public function __construct(private readonly string $directory, private readonly float $lockTimeoutSeconds = 5.0) {}
    public function mutate(string $group, string $actor, callable $callback): array
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0770, true) && !is_dir($this->directory)) $this->fail();
        $file = rtrim($this->directory, '/') . '/' . preg_replace('/[^a-z0-9_-]/i', '-', $group) . '-' . hash('sha256', $actor) . '.json';
        $lock = @fopen($file . '.lock', 'c+b');
        if ($lock === false) $this->fail();
        $temporary = null;
        try {
            $deadline = microtime(true) + max(0.0, $this->lockTimeoutSeconds);
            while (!flock($lock, LOCK_EX | LOCK_NB)) {
                if (microtime(true) >= $deadline) $this->fail();
                usleep(10_000);
            }
            $state = [];
            if (is_file($file)) {
                $json = @file_get_contents($file, false, null, 0, 1_048_577);
                if (!is_string($json) || $json === '' || strlen($json) > 1_048_576) $this->fail();
                try { $state = json_decode($json, true, 32, JSON_THROW_ON_ERROR); } catch (\JsonException) { $this->fail(); }
                if (!is_array($state) || !str_starts_with(ltrim($json), '{')) $this->fail();
            }
            $state = $callback($state);
            $json = json_encode((object) $state, JSON_THROW_ON_ERROR);
            if (strlen($json) > 1_048_576) $this->fail();
            $temporary = tempnam($this->directory, '.gate-');
            if ($temporary === false || !@chmod($temporary, 0660)) $this->fail();
            if (@file_put_contents($temporary, $json) !== strlen($json) || !@rename($temporary, $file)) $this->fail();
            $temporary = null; return $state;
        } finally {
            if (is_string($temporary)) @unlink($temporary);
            flock($lock, LOCK_UN); fclose($lock);
        }
    }
    private function fail(): never { throw new SoFinderException('The request limiter is unavailable or its state is damaged.', 'rate_limiter_unavailable', 503); }
}

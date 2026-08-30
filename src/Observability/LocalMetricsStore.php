<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Observability;

use SohoPHP\SoFinder\Contract\GaugeMetricsStoreInterface;

final class LocalMetricsStore implements GaugeMetricsStoreInterface
{
    public function __construct(private readonly string $file) {}

    public function increment(string $name, array $labels = [], int $amount = 1): void
    {
        $this->validate($name, $labels);
        $this->locked(function (array $state) use ($name, $labels, $amount): array {
            ksort($labels);
            $key = hash('sha256', json_encode([$name, $labels], JSON_THROW_ON_ERROR));
            $state[$key] = ['name' => $name, 'labels' => $labels, 'value' => max(0, (int) ($state[$key]['value'] ?? 0) + $amount)];
            return $state;
        });
    }

    public function snapshot(): array
    {
        $state = $this->locked(static fn (array $state): array => $state);
        $values = array_values(array_filter($state, static fn (mixed $item): bool => is_array($item) && is_string($item['name'] ?? null) && is_array($item['labels'] ?? null) && is_int($item['value'] ?? null)));
        usort($values, static fn (array $left, array $right): int => [$left['name'], json_encode($left['labels'])] <=> [$right['name'], json_encode($right['labels'])]);
        return $values;
    }

    public function set(string $name, int $value, array $labels = []): void
    {
        $this->validate($name, $labels);
        $this->locked(static function (array $state) use ($name, $value, $labels): array {
            ksort($labels);
            $key = hash('sha256', json_encode([$name, $labels], JSON_THROW_ON_ERROR));
            $state[$key] = ['name' => $name, 'labels' => $labels, 'value' => max(0, $value)];
            return $state;
        });
    }

    /**
     * @param callable(array<string,mixed>):array<string,mixed> $callback
     * @return array<string,mixed>
     */
    private function locked(callable $callback): array
    {
        $directory = dirname($this->file);
        if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) return [];
        $stream = @fopen($this->file, 'c+b');
        if ($stream === false || !flock($stream, LOCK_EX)) { if (is_resource($stream)) fclose($stream); return []; }
        try {
            $json = stream_get_contents($stream);
            $decoded = is_string($json) && $json !== '' ? json_decode($json, true) : [];
            $state = $callback(is_array($decoded) ? $decoded : []);
            rewind($stream);
            if (ftruncate($stream, 0)) { fwrite($stream, json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)); fflush($stream); }
            return $state;
        } finally { flock($stream, LOCK_UN); fclose($stream); }
    }

    /** @param array<string,string> $labels */
    private function validate(string $name, array $labels): void
    {
        if (preg_match('/^sofinder_[a-z][a-z0-9_]{1,63}$/D', $name) !== 1 || count($labels) > 8) throw new \InvalidArgumentException('The metric name or labels are invalid.');
        foreach ($labels as $key => $value) if (preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $key) !== 1 || strlen($value) > 64 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) throw new \InvalidArgumentException('The metric name or labels are invalid.');
    }
}

<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Security;

use SohoPHP\SoFinder\Exception\SoFinderException;
use SohoPHP\SoFinder\FileManager;
use SohoPHP\SoFinder\ResourceRegistry;
use SohoPHP\SoFinder\Value\Entry;

final readonly class SignedUrlManager
{
    public function __construct(
        private FileManager $files,
        private ResourceRegistry $resources,
        private PathGuard $paths,
        private bool $enabled,
        private string $secret,
        private int $defaultTtlSeconds = 300,
        private int $maxTtlSeconds = 3600,
        private ?\Closure $clock = null,
    ) {
        if ($this->enabled && strlen($this->secret) < 32) {
            throw new \InvalidArgumentException('SoFinder signed_urls.secret must contain at least 32 bytes when signed URLs are enabled.');
        }
    }

    /** @return array{token:string,expiresAt:int,entry:Entry} */
    public function issue(string $resource, string $path, ?int $ttlSeconds = null, string $disposition = 'attachment'): array
    {
        $this->assertEnabled();
        $item = $this->resources->get($resource);
        if ($item->resource->deliveryMode !== 'proxy') {
            throw new SoFinderException('Signed URLs are only available for private proxy resources.', 'signed_url_public_resource', 422);
        }
        $entry = $this->files->entry($resource, $path);
        if ($entry->directory) throw new SoFinderException('Folders cannot use signed content URLs.', 'invalid_type', 400);
        $ttl = $ttlSeconds ?? $this->defaultTtlSeconds;
        if ($ttl < 30 || $ttl > $this->maxTtlSeconds) {
            throw new SoFinderException('The requested signed URL lifetime is outside the configured range.', 'signed_url_ttl_invalid', 422);
        }
        $disposition = strtolower($disposition) === 'inline' ? 'inline' : 'attachment';
        $expiresAt = $this->now() + $ttl;
        $payload = $this->encode(json_encode([
            'v' => 1,
            'resource' => $resource,
            'path' => $entry->path,
            'size' => $entry->size,
            'modifiedAt' => $entry->modifiedAt,
            'disposition' => $disposition,
            'expiresAt' => $expiresAt,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $signature = $this->encode(hash_hmac('sha256', $payload, $this->secret, true));

        return ['token' => $payload . '.' . $signature, 'expiresAt' => $expiresAt, 'entry' => $entry];
    }

    /** @return array{resource:string,entry:Entry,stream:resource,disposition:string,expiresAt:int} */
    public function open(string $token): array
    {
        $this->assertEnabled();
        if (preg_match('/^([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]+)$/D', $token, $matches) !== 1) {
            throw new SoFinderException('The signed URL token is invalid.', 'signed_url_invalid', 403);
        }
        $expected = hash_hmac('sha256', $matches[1], $this->secret, true);
        $provided = $this->decode($matches[2]);
        if ($provided === null || !hash_equals($expected, $provided)) {
            throw new SoFinderException('The signed URL token is invalid.', 'signed_url_invalid', 403);
        }
        $json = $this->decode($matches[1]);
        $payload = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($payload)
            || ($payload['v'] ?? null) !== 1
            || !is_string($payload['resource'] ?? null)
            || !is_string($payload['path'] ?? null)
            || !is_int($payload['size'] ?? null)
            || !is_int($payload['modifiedAt'] ?? null)
            || !is_int($payload['expiresAt'] ?? null)
            || !in_array($payload['disposition'] ?? null, ['inline', 'attachment'], true)
        ) {
            throw new SoFinderException('The signed URL payload is invalid.', 'signed_url_invalid', 403);
        }
        if ($payload['expiresAt'] < $this->now()) {
            throw new SoFinderException('The signed URL has expired.', 'signed_url_expired', 410);
        }
        $path = $this->paths->normalize($payload['path']);
        if ($path !== $payload['path']) throw new SoFinderException('The signed URL path is invalid.', 'signed_url_invalid', 403);
        $item = $this->resources->get($payload['resource']);
        if ($item->resource->deliveryMode !== 'proxy') {
            throw new SoFinderException('The signed URL resource is no longer private.', 'signed_url_invalid', 403);
        }
        $entry = $item->storage->entry($path);
        if ($entry->directory || $entry->size !== $payload['size'] || $entry->modifiedAt !== $payload['modifiedAt']) {
            throw new SoFinderException('The file changed after this signed URL was issued.', 'signed_url_stale', 410);
        }
        $stream = $item->storage->readStream($path);

        return [
            'resource' => $payload['resource'],
            'entry' => $entry,
            'stream' => $stream,
            'disposition' => $payload['disposition'],
            'expiresAt' => $payload['expiresAt'],
        ];
    }

    private function assertEnabled(): void
    {
        if (!$this->enabled) throw new SoFinderException('Signed URLs are disabled.', 'signed_urls_disabled', 404);
    }

    private function now(): int
    {
        return $this->clock !== null ? (int) ($this->clock)() : time();
    }

    private function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function decode(string $value): ?string
    {
        $padding = (4 - strlen($value) % 4) % 4;
        $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', $padding), true);

        return is_string($decoded) && $this->encode($decoded) === $value ? $decoded : null;
    }
}

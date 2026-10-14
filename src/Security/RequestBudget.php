<?php
declare(strict_types=1);
namespace SohoPHP\SoFinder\Security;
use SohoPHP\SoFinder\Contract\ActorProviderInterface;
use SohoPHP\SoFinder\Contract\RequestGateStoreInterface;
use SohoPHP\SoFinder\Exception\SoFinderException;
/** Shared processing budgets for every HTTP bridge. */
final class RequestBudget
{
    /** @param array<string,array{max_requests:int,interval:int,max_concurrent:int}> $limits */
    public function __construct(private readonly RequestGateStoreInterface $store, private readonly ActorProviderInterface $actors, private readonly array $limits, private readonly ?\SohoPHP\SoFinder\Contract\AuthorizationInterface $authorization = null) {}
    public static function group(string $endpoint): ?string
    {
        return match ($endpoint) {
            'sofinder_browser', 'sofinder_asset', 'sofinder_liveness' => null,
            'sofinder_api_upload', 'sofinder_api_chunk_upload', 'sofinder_api_chunk_cancel', 'sofinder_quick_upload' => 'upload',
            'sofinder_image_edit', 'sofinder_image_batch', 'sofinder_image_variant', 'sofinder_image_info' => 'image',
            'sofinder_image_thumbnail' => 'thumbnail',
            'sofinder_archive_download' => 'archive',
            'sofinder_api_copy', 'sofinder_api_move', 'sofinder_api_batch', 'sofinder_api_batch_rename' => 'transfer',
            default => str_starts_with($endpoint, 'sofinder_') ? 'normal' : null,
        };
    }
    /** @return array{string,string,string}|null */
    public function acquire(string $endpoint, string $anonymousSource = 'unknown'): ?array
    {
        $group = self::group($endpoint);
        if ($group === null || !isset($this->limits[$group])) return null;
        $limit = $this->limits[$group];
        if ($limit['max_requests'] < 1 && $limit['max_concurrent'] < 1) return null;
        $actor = $this->authorization !== null && !$this->authorization->isAuthenticated()
            ? 'anonymous:' . hash('sha256', $anonymousSource) : $this->actors->actorId();
        $lease = bin2hex(random_bytes(12)); $now = time();
        $this->store->mutate($group, $actor, static function (array $state) use ($limit, $lease, $now): array {
            if ($state !== [] && (!is_int($state['window_start'] ?? null) || !is_int($state['count'] ?? null)
                || $state['count'] < 0 || !is_array($state['active'] ?? null))) {
                throw new SoFinderException('The request limiter state is invalid.', 'rate_limiter_unavailable', 503);
            }
            $start = $state['window_start'] ?? $now; $count = $state['count'] ?? 0;
            if ($start + $limit['interval'] <= $now) { $start = $now; $count = 0; }
            $active = $state['active'] ?? [];
            foreach ($active as $key => $value) {
                if (!is_string($key) || !is_int($value)) throw new SoFinderException('The request limiter state is invalid.', 'rate_limiter_unavailable', 503);
            }
            $active = array_filter($active, static fn (int $started): bool => $started > $now - 900);
            if ($limit['max_requests'] > 0 && $count >= $limit['max_requests']) throw new SoFinderException('Too many SoFinder requests. Please retry later.', 'rate_limit_exceeded', 429);
            if ($limit['max_concurrent'] > 0 && count($active) >= $limit['max_concurrent']) throw new SoFinderException('Too many concurrent SoFinder operations.', 'concurrency_limit_exceeded', 429);
            $active[$lease] = $now;
            return ['window_start' => $start, 'count' => $count + 1, 'active' => $active];
        });
        return [$group, $actor, $lease];
    }
    /** @param array{string,string,string}|null $lease */
    public function release(?array $lease): void
    {
        if ($lease === null) return;
        $this->store->mutate($lease[0], $lease[1], static function (array $state) use ($lease): array {
            if (!is_array($state['active'] ?? null)) throw new SoFinderException('The request limiter state is invalid.', 'rate_limiter_unavailable', 503);
            unset($state['active'][$lease[2]]); return $state;
        });
    }
}

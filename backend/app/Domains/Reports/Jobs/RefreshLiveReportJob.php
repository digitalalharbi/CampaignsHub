<?php

declare(strict_types=1);

namespace App\Domains\Reports\Jobs;

use App\Domains\Reports\Services\LiveReportCache;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * PERF-RECOVERY-001 — rebuild one live link's stored answer, off the request path.
 *
 * On `live-refresh`, which only the niced `supervisor-sync` works in production (after `sync` jobs
 * finish their current one, never beside them), so a refresh yields the CPU to the web tier exactly
 * as a provider sync does.
 *
 * One try and no retry: a refresh that fails leaves the previous build in place and records why, and
 * the next stale read asks again. Retrying a 3 to 25 s build in a loop on one CPU is the stampede this
 * exists to prevent.
 *
 * Unique per cache key for at most the serving bound, so concurrent stale reads of one link queue one
 * job; the lock is released when the job ends and expires on its own if a worker dies holding it.
 */
final class RefreshLiveReportJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    /** @param array<string, mixed> $query the canonical query the key was made from */
    public function __construct(
        public readonly string $shareId,
        public readonly array $query,
        public readonly string $currency,
        public readonly string $key,
        public readonly string $locale,
    ) {
        $this->onQueue((string) config('reports.live_cache.queue', 'live-refresh'));
    }

    public function uniqueId(): string
    {
        return $this->key;
    }

    public function uniqueFor(): int
    {
        return max(1, (int) config('reports.live_cache.max_age_seconds', 900));
    }

    public function handle(LiveReportCache $cache): void
    {
        $cache->refreshNow($this->shareId, $this->query, $this->currency, $this->key, $this->locale);
    }

    /** A worker timeout or a crash outside the build: the previous answer stays, and the reason is kept. */
    public function failed(Throwable $e): void
    {
        app(LiveReportCache::class)->recordFailure($this->key, $this->shareId, $e::class.': '.$e->getMessage());
    }
}

<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Support;

use Closure;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * ANALYTICS-DATA-001 — the per-tenant, per-project read cache of the analytics sections.
 *
 * Same pattern as `WorkspaceReadCache` (PERF-BOOTSTRAP-001): only the ANSWER is cached, after the caller has been
 * authorised by the route; the key can never lose its tenant or its project, so two projects — of one tenant or of
 * two — never share an entry; an epoch in the key abandons every entry of ONE project at once; and a dead cache
 * store fails nothing (the answer is computed, and the process does not retry for 30 seconds).
 *
 * What differs: the epoch is per project, bumped by the things that change what an analytics read says and are
 * written through our own code — a stored snapshot, an influencer link created or edited. A sync that lands new
 * rows does not bump it; the 5-minute TTL is the bound on that staleness, and it is stated on every payload as
 * `cached_at`.
 *
 * What is cached is the payload BEFORE any reader-specific cut (a hidden cost column, a hidden GA4 element): the
 * controller scrubs after the cache, so one reader's permission can never be served to another.
 */
final class AnalyticsReadCache
{
    public const TTL_SECONDS = 300;

    private const FAILED = "\0cache-unavailable\0";

    private static int $downUntil = 0;

    /**
     * @template T
     *
     * @param  Closure(): T  $compute
     * @return T
     */
    public static function remember(string $section, string $tenantId, string $projectId, string $variant, Closure $compute): mixed
    {
        // No tenant or no project: the answer cannot be filed, so it is not cached at all.
        if ($tenantId === '' || $projectId === '') {
            return $compute();
        }

        $key = self::key($section, $tenantId, $projectId, $variant);
        if ($key === null) {
            return $compute();
        }

        $hit = self::guarded(static fn (): mixed => Cache::get($key));
        if (is_array($hit) && array_key_exists('v', $hit)) {
            return $hit['v'];
        }

        $value = $compute();
        self::guarded(static fn (): mixed => Cache::put($key, ['v' => $value], self::TTL_SECONDS));

        return $value;
    }

    /** Abandon every cached section of one project. */
    public static function bump(string $tenantId, string $projectId): void
    {
        self::guarded(static fn (): mixed => Cache::increment(self::epochKey($tenantId, $projectId)));
    }

    public static function key(string $section, string $tenantId, string $projectId, string $variant): ?string
    {
        $epoch = self::guarded(static fn (): mixed => Cache::get(self::epochKey($tenantId, $projectId)));
        if ($epoch === self::FAILED) {
            return null;
        }

        return 'an:v1:'.$section.':e'.(int) $epoch.':t:'.$tenantId.':p:'.$projectId.':'.$variant;
    }

    private static function epochKey(string $tenantId, string $projectId): string
    {
        return 'an:v1:epoch:t:'.$tenantId.':p:'.$projectId;
    }

    private static function guarded(Closure $call): mixed
    {
        if (self::$downUntil > time()) {
            return self::FAILED;
        }

        try {
            return $call();
        } catch (Throwable) {
            self::$downUntil = time() + 30;

            return self::FAILED;
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Services;

use App\Domains\Analytics\Support\AnalyticsReadCache;
use App\Domains\Analytics\Support\AnalyticsScope;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * ANALYTICS-DATA-001 §9 — the one service-layer read API of the analytics sections.
 *
 * The HTTP controller and the read-only MCP server both call this, so they can never answer differently. It states
 * its own tenant and project scope ({@see AnalyticsScope}), reads a fresh stored snapshot when one exists
 * ({@see AnalyticsSnapshots}), and otherwise computes — behind the per-tenant, per-project cache. It authorises
 * nothing: the caller has already proved the reader may see this project, and applies the reader's own cuts (the
 * cost column, the sales elements) AFTER, on the returned copy.
 */
final class AnalyticsReads
{
    public const SITE = 'site';

    public const STORE = 'store';

    public const INFLUENCERS = 'influencers';

    public const ATTRIBUTION = 'attribution';

    public const SECTIONS = [self::SITE, self::STORE, self::INFLUENCERS, self::ATTRIBUTION];

    /** The sections heavy enough to be pre-aggregated; the site read is one GA4 table scan and stays live. */
    public const PRE_AGGREGATED = [self::STORE, self::INFLUENCERS, self::ATTRIBUTION];

    public function __construct(
        private readonly SiteReadModel $site,
        private readonly StoreReadModel $store,
        private readonly InfluencerPerformance $influencers,
        private readonly UnifiedAttribution $attribution,
        private readonly AnalyticsSnapshots $snapshots,
    ) {}

    /** @return array<string, mixed> */
    public function site(string $tenantId, string $projectId, Carbon $from, Carbon $to): array
    {
        return $this->read(self::SITE, $tenantId, $projectId, $from, $to);
    }

    /** @return array<string, mixed> */
    public function store(string $tenantId, string $projectId, Carbon $from, Carbon $to): array
    {
        return $this->read(self::STORE, $tenantId, $projectId, $from, $to);
    }

    /** @return array<string, mixed> */
    public function influencers(string $tenantId, string $projectId, Carbon $from, Carbon $to): array
    {
        return $this->read(self::INFLUENCERS, $tenantId, $projectId, $from, $to);
    }

    /** @return array<string, mixed> */
    public function attribution(string $tenantId, string $projectId, Carbon $from, Carbon $to): array
    {
        return $this->read(self::ATTRIBUTION, $tenantId, $projectId, $from, $to);
    }

    /** @return array<string, mixed> */
    public function read(string $section, string $tenantId, string $projectId, Carbon $from, Carbon $to): array
    {
        if (! in_array($section, self::SECTIONS, true)) {
            throw new InvalidArgumentException("Unknown analytics section: {$section}");
        }

        return AnalyticsReadCache::remember(
            $section, $tenantId, $projectId, $from->toDateString().'_'.$to->toDateString(),
            fn (): array => $this->snapshotOrCompute($section, $tenantId, $projectId, $from, $to),
        );
    }

    /**
     * Compute now, bypassing both the cache and the snapshot — what the pre-aggregation job stores.
     *
     * @return array<string, mixed>
     */
    public function compute(string $section, string $tenantId, string $projectId, Carbon $from, Carbon $to): array
    {
        $payload = AnalyticsScope::run($tenantId, $projectId, fn (): array => match ($section) {
            self::SITE => $this->site->build($tenantId, $projectId, $from, $to),
            self::STORE => $this->store->build($tenantId, $projectId, $from, $to),
            self::INFLUENCERS => $this->influencers->build($tenantId, $projectId, $from, $to),
            self::ATTRIBUTION => $this->attribution->build($tenantId, $projectId, $from, $to),
            default => throw new InvalidArgumentException("Unknown analytics section: {$section}"),
        });

        return $payload + ['computed_at' => Carbon::now()->toIso8601String()];
    }

    /** @return array<string, mixed> */
    private function snapshotOrCompute(string $section, string $tenantId, string $projectId, Carbon $from, Carbon $to): array
    {
        if (in_array($section, self::PRE_AGGREGATED, true)) {
            $fresh = $this->snapshots->fresh($tenantId, $projectId, $section, $from->toDateString(), $to->toDateString());
            if ($fresh !== null) {
                return $fresh;
            }
        }

        return $this->compute($section, $tenantId, $projectId, $from, $to);
    }
}

<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Providers;

use App\Domains\Integrations\ValueObjects\SyncResult;

/**
 * AUDIENCE-001 — a provider that reports PAID DELIVERY broken down by an audience dimension.
 *
 * A provider declares only the dimensions its API documents and this product actually asks for
 * (`audienceDimensions()`); the syncer asks nothing else. Rows are in the shape
 * `UpsertAudienceBreakdownRows` reads: `entity_id` (the provider's ad set id), `campaign_id`,
 * `date`, `value` (the provider's label, verbatim), and whichever of `impressions`, `reach`,
 * `clicks`, `spend`, `currency` it reported — a measure it did not report is ABSENT, never zero.
 */
interface ReportsAudienceBreakdowns
{
    /** @return list<string> the dimensions this provider is asked for, e.g. ['region'] */
    public function audienceDimensions(): array;

    /** The provider field each dimension is read from, for provenance (e.g. `meta:insights.breakdowns=region`). */
    public function audienceSourceField(string $dimension): string;

    /**
     * @param  array<string, list<string>>  $delivering  the provider's ad-set ids that DELIVERED in the window → their days
     *                                                   (impressions > 0). A provider that must ask per ad set and day
     *                                                   (Snapchat) asks only these; one that answers per account ignores it.
     */
    public function audienceBreakdown(string $adAccountId, string $dimension, string $from, string $to, array $delivering = []): SyncResult;
}

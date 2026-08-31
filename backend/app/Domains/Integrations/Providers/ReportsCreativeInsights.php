<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Providers;

use App\Domains\Integrations\ValueObjects\SyncResult;

/**
 * SNAP-CREATIVE-METRICS-001 — a connector that can report stats at the CREATIVE level.
 *
 * Declared as a capability rather than added to `ApiAdvertisingConnector`, because it is not one.
 * Google Ads and LinkedIn send no creative with an ad at all, and a platform that cannot answer the
 * question should not be forced to implement a method that returns an empty array — an adapter
 * pretending to support something is how «0 creatives» becomes indistinguishable from «not asked».
 *
 * The syncer checks for this interface and skips the extra call entirely for anyone else, so no
 * provider pays a round trip for a level it does not have.
 */
interface ReportsCreativeInsights
{
    /**
     * Daily stats per creative, in the canonical row shape.
     *
     * The provider's own creative id arrives in `campaign_id` — the shared row shape names that key
     * for the entity the row belongs to, whatever level was asked for. Resolving it to one of our
     * creatives is the caller's job.
     *
     * Mirrors `syncInsights()`: the connector holds its own tokens and reports failure as a result
     * rather than an exception, so a caller never has to reach inside it for a credential.
     */
    public function syncCreativeInsights(string $adAccountId, string $from, string $to): SyncResult;
}

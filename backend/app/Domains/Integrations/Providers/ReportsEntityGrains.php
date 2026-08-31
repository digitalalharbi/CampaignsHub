<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Providers;

use App\Domains\Integrations\ValueObjects\SyncResult;

/**
 * ADSET-METRICS-TRUTH-001 — a provider that can report the two rungs between a campaign and an ad.
 *
 * ## Why this interface exists
 *
 * `AccountMetricsSyncer` asked for the ad-set and ad grains behind `if ($connector instanceof
 * SnapchatConnector)`. Everything underneath it — the sweep, the upsert, the aggregator, the
 * drill-down, the Analytics tabs — was written to be provider-agnostic and worked for any of them.
 * One `instanceof` decided that only ONE of eight platforms would ever fill the table.
 *
 * So a Meta account showed «—» for ad-set spend, CPC, CPM and CPA — not because Meta withholds
 * them, which it does not, but because nothing ever asked. That reads to an operator as a platform
 * that reports less than it does, and it is our sentence rather than the provider's.
 *
 * ## What a provider promises by implementing it
 *
 * Rows in the canonical shape the upsert reads: the provider's own entity id in `entity_id`, a
 * `date`, and whichever measures it actually reported. A measure it does not report is ABSENT from
 * the row, never zero — the whole product's distinction between «not provided» and «none» starts
 * here, at the row the connector builds.
 *
 * A provider that cannot report a grain simply does not implement this, and the syncer does not ask
 * — which is a different and honest state from asking and getting nothing.
 */
interface ReportsEntityGrains
{
    /** The two rungs, named as the metrics table names them. */
    public const AD_SET = 'ad_set';

    public const AD = 'ad';

    /**
     * One grain of one account, for one window.
     *
     * `$campaignExternalIds` is passed for the providers whose API offers no account-wide breakdown
     * and must sweep each parent in turn — Snapchat's `breakdown=adsquad` lives on the campaign
     * stats endpoint, so the sweep is 89 calls rather than one. A provider that can answer for the
     * whole account at once ignores the list.
     *
     * @param  self::AD_SET|self::AD  $grain
     * @param  list<string>  $campaignExternalIds
     */
    public function entityInsights(
        string $adAccountId,
        string $grain,
        array $campaignExternalIds,
        string $from,
        string $to,
    ): SyncResult;

    /**
     * The first refusal of the last sweep, or null when every call answered.
     *
     * An empty grain has two entirely different causes — nothing has been swept yet, or the platform
     * refused — and a row count cannot tell them apart. The run log records this beside the counts.
     */
    public function lastEntityFailure(): ?string;
}

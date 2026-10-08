<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * CREATIVE-GRAIN-TRUTH-001 — whether a campaign's RESULTS are attributed at creative grain at all.
 *
 * The owner, with a screenshot: «يوجد خطأ فادح — كيف حققت الحملة أداء عائد إلى 5x بالمقابل
 * المحتويات العائد لها ضعيف جداً». A campaign reporting 109 purchases sat above creatives every one
 * of which read «الطلبات 0» and «0.00x».
 *
 * Neither number was wrong on its own, and that is exactly what made it unreadable. Several
 * platforms report conversions against the CAMPAIGN or the ad set and return a flat zero for the
 * same conversion on each creative — the attribution was never broken down, so the zero is the
 * platform saying «not at this grain», not «nobody bought».
 *
 * The pipeline is already careful about the other half of this: a metric the account does not report
 * arrives as an ABSENT key, the sums are deliberately un-coalesced, and `CreativeMetrics` drops a
 * metric the row cannot answer. None of that helps here, because the platform DID answer — with a
 * zero it is not entitled to be read as a result.
 *
 * ## The signal, and why it is per campaign rather than per creative
 *
 * A single creative reading zero inside a performing campaign is ordinary: that one did not sell.
 * What is NOT ordinary is EVERY creative under a campaign reading zero while the campaign reports
 * results. One creative's zero is a result; all of them together, under a campaign that sold, is a
 * missing breakdown.
 *
 * So the question is asked once per campaign over the window:
 *
 *   campaign-grain results > 0   AND   the sum over its creatives is zero or absent
 *       → creative-grain attribution is NOT established for this campaign
 *
 * and every creative under it reports those metrics as not attributable rather than as zero. Where
 * even one creative carries a result, the breakdown exists and a zero elsewhere is a real zero.
 *
 * ## What this never does
 *
 * It never allocates the campaign's results downward. A campaign reading 5x does not license giving
 * any creative 5x, a share of 5x, or an estimate — constitution §5. The only thing established here
 * is whether the question can be answered at this grain, and when it cannot, the surfaces say so.
 */
final class CreativeResultAttribution
{
    /**
     * The metrics this is about: what the money BOUGHT and what it returned.
     *
     * Delivery figures — impressions, clicks, views — are reported per creative by every platform
     * that reports creatives at all, and are not in question. It is the conversion family that gets
     * attributed at a higher grain and flattened to zero here.
     */
    public const RESULT_METRICS = ['conversions', 'purchases', 'revenue', 'roas', 'aov', 'cpa', 'leads', 'installs'];

    /** The columns read to decide it, on both sides of the comparison. */
    private const EVIDENCE = ['conversions', 'revenue'];

    /**
     * Campaign ids whose results are NOT attributed at creative grain in this window.
     *
     * @param  list<string>  $campaignIds
     * @return array<string, true> keyed by campaign id, so a caller can ask `isset()`
     */
    public function unattributedCampaigns(array $campaignIds, Carbon $from, Carbon $to): array
    {
        $campaignIds = array_values(array_filter(array_unique($campaignIds)));

        if ($campaignIds === []) {
            return [];
        }

        $campaign = $this->campaignTotals($campaignIds, $from, $to);
        $creative = $this->creativeTotals($campaignIds, $from, $to);

        $out = [];

        foreach ($campaignIds as $id) {
            $soldAtCampaignGrain = ($campaign[$id]['conversions'] ?? 0) > 0 || ($campaign[$id]['revenue'] ?? 0) > 0;

            if (! $soldAtCampaignGrain) {
                /*
                 * The campaign reports no result either. Then a creative's zero is not contradicted
                 * by anything and stays a reported zero — an awareness buy that sold nothing is not
                 * an attribution failure, and marking it as one would hide a true figure.
                 */
                continue;
            }

            $brokenDown = ($creative[$id]['conversions'] ?? 0) > 0 || ($creative[$id]['revenue'] ?? 0) > 0;

            if (! $brokenDown) {
                $out[$id] = true;
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $campaignIds
     * @return array<string, array{conversions: float, revenue: float}>
     */
    private function campaignTotals(array $campaignIds, Carbon $from, Carbon $to): array
    {
        /*
          Queried through `DB::table`, like every other sum in `CreativeMetrics`: these are
          aggregates, not entities, and routing them through Eloquent would hydrate thousands of
          models to add two columns.
        */
        /** @var array<int, object{entity_id: string, conversions: ?float, revenue: ?float}> $rows */
        $rows = DB::table('entity_daily_metrics')
            ->where('entity_type', 'campaign')
            ->whereIn('entity_id', $campaignIds)
            ->whereBetween('metric_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('entity_id, sum(conversions) as conversions, sum(revenue) as revenue')
            ->groupBy('entity_id')
            ->get()
            ->all();

        return $this->keyed($rows, 'entity_id');
    }

    /**
     * @param  list<string>  $campaignIds
     * @return array<string, array{conversions: float, revenue: float}>
     */
    private function creativeTotals(array $campaignIds, Carbon $from, Carbon $to): array
    {
        /** @var array<int, object{campaign_id: string, conversions: ?float, revenue: ?float}> $rows */
        $rows = DB::table('creative_daily_metrics')
            ->whereIn('campaign_id', $campaignIds)
            ->whereBetween('metric_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('campaign_id, sum(conversions) as conversions, sum(revenue) as revenue')
            ->groupBy('campaign_id')
            ->get()
            ->all();

        return $this->keyed($rows, 'campaign_id');
    }

    /**
     * @param  array<int, object>  $rows
     * @return array<string, array{conversions: float, revenue: float}>
     */
    private function keyed(array $rows, string $key): array
    {
        $out = [];

        foreach ($rows as $row) {
            $out[(string) $row->{$key}] = [
                'conversions' => (float) ($row->conversions ?? 0),
                'revenue' => (float) ($row->revenue ?? 0),
            ];
        }

        return $out;
    }
}

<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Services;

use App\Domains\Integrations\Services\BoundAccountVisibility;
use App\Domains\Tenancy\Context\TenantContext;
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
     *                                     `conversions` and `revenue` are the evidence on BOTH sides of the comparison, and they are
     *                                     named in the two `selectRaw` calls rather than in a constant: a list declared once and then
     *                                     hand-written into each query is two statements that can disagree, and PHPStan was right that
     *                                     the constant was carrying none of the meaning.
     * @return array<string, array{conversions: float, revenue: float}>
     */
    private function campaignTotals(array $campaignIds, Carbon $from, Carbon $to): array
    {
        /*
          Campaign results live in `daily_metrics`, not in `entity_daily_metrics`.

          The first version of this read `entity_daily_metrics where entity_type = 'campaign'` and
          would never have matched a single row: that table holds `ad_set` and `ad` grains only —
          checked against the database rather than assumed. The campaign grain is `daily_metrics`,
          keyed by `unified_campaign_id` with one row per `metric_key`, which is also the id
          `external_creatives.campaign_id` carries, so both sides of the comparison key alike.

          Narrowed through the one class that owns account visibility: a project with a deselected
          second account would otherwise count that account's campaign sales here while the creative
          side never sees its creatives, and every creative in the project would be reported
          unattributable on the strength of rows the project may not show.
        */
        /** @var array<int, object{unified_campaign_id: string, metric_key: string, total: ?float}> $rows */
        $rows = DB::table('daily_metrics')
            /*
             * The tenant predicate is written out because `DB::table()` has no global scope.
             *
             * Eloquent models carry `BelongsToTenant`; a raw builder carries nothing, so a service
             * that reaches for the query builder — as every aggregate in this area does, to avoid
             * hydrating thousands of models — has to state the tenant itself. The cross-tenant test
             * failed without this, and it was right to: `BoundAccountVisibility` narrows by
             * BINDING, which is a project-level fact and never claimed to be a tenant boundary.
             */
            ->where('tenant_id', app(TenantContext::class)->tenantId())
            ->whereIn('unified_campaign_id', $campaignIds)
            ->whereIn('metric_key', ['conversions', 'revenue'])
            ->whereBetween('metric_date', [$from->toDateString(), $to->toDateString()])
            ->tap(fn ($q) => BoundAccountVisibility::apply($q, 'daily_metrics'))
            ->selectRaw('unified_campaign_id, metric_key, sum(value) as total')
            ->groupBy('unified_campaign_id', 'metric_key')
            ->get()
            ->all();

        $out = [];

        foreach ($rows as $row) {
            $id = (string) $row->unified_campaign_id;
            $out[$id] ??= ['conversions' => 0.0, 'revenue' => 0.0];
            $out[$id][$row->metric_key] = (float) ($row->total ?? 0);
        }

        return $out;
    }

    /**
     * @param  list<string>  $campaignIds
     * @return array<string, array{conversions: float, revenue: float}>
     */
    private function creativeTotals(array $campaignIds, Carbon $from, Carbon $to): array
    {
        /** @var array<int, object{campaign_id: string, conversions: ?float, revenue: ?float}> $rows */
        $rows = DB::table('creative_daily_metrics')
            /* The same reason as the campaign side: a raw builder carries no tenant scope. */
            ->where('tenant_id', app(TenantContext::class)->tenantId())
            ->whereIn('campaign_id', $campaignIds)
            ->whereBetween('metric_date', [$from->toDateString(), $to->toDateString()])
            /* The same rule, in the spelling this table needs — see the note on the campaign side. */
            ->tap(fn ($q) => BoundAccountVisibility::applyThroughCampaign(
                $q,
                '(select cr.external_campaign_id from external_creatives cr where cr.id = creative_daily_metrics.creative_id)',
                'creative_daily_metrics.project_id',
            ))
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

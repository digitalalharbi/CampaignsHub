<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Campaigns\Models\ExternalAd;
use App\Domains\Campaigns\Models\ExternalAdSet;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\Metrics\Models\EntityDailyMetric;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * CONTENT-DETAIL-MODAL-001 — the ad row an operator can actually open a preview from.
 *
 * ## The gap this fills, measured rather than assumed
 *
 * The row has read «no ad row in this install's demo data has a matched creative, so the preview
 * control is not drawn and the surface cannot be exercised here» for as long as it has been open.
 * Asked of the seeded database directly, it is true and it is worse than it sounds:
 *
 *   - the store project holds 85 ads and 60 creatives and ZERO links between them, and
 *   - NOTHING in this repository writes `entity_daily_metrics` at all.
 *
 * So the Analytics ad table is empty in every project, and `creativeByAd` — which matches a
 * creative's ads against an entity row's `external_id` — has nothing on either side to match. The
 * modal could not be exercised because there was no row to open it from, not merely because the
 * link was missing.
 *
 * ## What it does, and what it refuses to invent
 *
 * For a bounded number of ads per project: it points the ad at a creative of the SAME provider that
 * is already there, and writes ad-level `entity_daily_metrics` across the demo window so the row
 * reaches the table. Nothing here creates media. The store project's creatives are `estimated` —
 * derived from ad-level performance rather than fetched — so the preview a reader opens is the
 * honest `never_fetched` absence, which is the state AD-PREVIEW-001 is about and the correct thing
 * for this data to show. A fixture that granted them assets would be a picture of a product that
 * does not exist.
 *
 * Everything written is flagged `is_demo`, which is what keeps it out of Production totals.
 */
final class DemoAdCreativeLinkSeeder extends Seeder
{
    /** Enough to open the surface, few enough that the demo world stays readable. */
    private const ADS_PER_PROJECT = 3;

    private const DAYS = 14;

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn('Demo ad/creative links are development-only — skipped.');

            return;
        }

        $ads = ExternalAd::withoutGlobalScopes()
            ->whereNull('creative_id')
            ->orderBy('project_id')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (ExternalAd $ad): string => (string) $ad->project_id.'|'.(string) $ad->provider);

        $linked = 0;

        foreach ($ads as $key => $group) {
            [$projectId, $provider] = explode('|', (string) $key);

            $creative = ExternalCreative::withoutGlobalScopes()
                ->where('project_id', $projectId)
                ->where('provider', $provider)
                ->first();

            if ($creative === null) {
                continue;
            }

            /*
             * Idempotent per group, because `db:seed` is run twice more often than anyone admits.
             *
             * The filter above is «ads with no creative», so a second run would happily link three
             * MORE ads in the same group and write another fourteen days of rows for each — a demo
             * world that grows every time the command is repeated. `DatabaseSeeder` already carries a
             * paragraph about exactly that failure costing twelve creatives; this one declines to add
             * a second instance of it.
             */
            $already = ExternalAd::withoutGlobalScopes()
                ->where('project_id', $projectId)
                ->where('provider', $provider)
                ->whereNotNull('creative_id')
                ->count();

            if ($already >= self::ADS_PER_PROJECT) {
                continue;
            }

            foreach ($group->take(self::ADS_PER_PROJECT - $already) as $ad) {
                $ad->forceFill(['creative_id' => $creative->getKey()])->saveQuietly();
                $this->metrics($ad);
                $linked++;
            }
        }

        /*
         * At least one ad per OBJECTIVE has ad-level metrics — OBJECTIVE-ANALYTICS-DEPTH-001.
         *
         * The grouping above is by project and provider, and the demo's providers all carry `sales`
         * campaigns, so every ad that reached the entity table belonged to one objective. The Ads table
         * must show a sales buy its return and an awareness buy its reach, and must REFUSE to blend the
         * two when the rows span both — and with one objective in the table, the mixed case was
         * unreachable in a browser and the single-family case was indistinguishable from the fixed column
         * set it replaced.
         *
         * Written for ads that have none rather than for a fixed count, so a second run adds nothing —
         * the same idempotence the linking step above is careful about.
         */
        $withMetrics = EntityDailyMetric::withoutGlobalScopes()
            ->where('entity_type', 'ad')
            ->distinct()
            ->pluck('entity_id')
            ->all();

        $ads = ExternalAd::withoutGlobalScopes()
            ->whereNotNull('unified_campaign_id')
            ->get(['id', 'project_id', 'provider', 'tenant_id', 'unified_campaign_id', 'external_id', 'external_campaign_id', 'external_ad_set_id']);

        $objectives = UnifiedCampaign::withoutGlobalScopes()
            ->whereIn('id', $ads->pluck('unified_campaign_id')->filter()->unique()->values()->all())
            ->pluck('objective', 'id');

        /*
         * IDEMPOTENCE, and it took a correction to get right.
         *
         * A first version excluded ads that already had metrics and then picked one ad per
         * project-and-objective from what remained — so every run found a DIFFERENT unmetriced ad for the
         * same objective and wrote another fourteen days for it. Two more ads per `db:seed`, which is the
         * demo world that grows on repetition this file's own docblock warns about.
         *
         * The question is «does this project's objective already have an ad with metrics», not «does this
         * ad have them». So the covered set is built from the ads that ALREADY have rows, before anything
         * is written.
         */
        $hasRows = array_fill_keys($withMetrics, true);
        $covered = [];
        foreach ($ads as $ad) {
            if (isset($hasRows[(string) $ad->getKey()])) {
                $covered[(string) $ad->project_id.'|'.(string) ($objectives[$ad->unified_campaign_id] ?? '')] = true;
            }
        }

        $forObjective = 0;
        foreach ($ads as $ad) {
            $objective = (string) ($objectives[$ad->unified_campaign_id] ?? '');
            $seen = (string) $ad->project_id.'|'.$objective;

            if ($objective === '' || isset($covered[$seen]) || isset($hasRows[(string) $ad->getKey()])) {
                continue;
            }

            $this->metrics($ad);
            $covered[$seen] = true;
            $forObjective++;
        }

        $squads = $this->adSetMetrics();

        /*
         * Counted per step, because one number for both was misleading.
         *
         * `$linked` was incremented by the linking step and by this one, so a re-run that inserted
         * nothing still reported «2 ads now carry … metrics» — an operator reading that would believe
         * the seeder had written rows it had not. `metrics()` is a plain `insert`, so «did it run
         * again» is a question with real consequences, and the message has to answer it.
         */
        $this->command?->info("Demo: {$linked} ads linked to a creative, {$forObjective} given ad-level metrics for their objective, {$squads} ad sets given the grain above them.");
    }

    /**
     * Ad-level rows across the window, so the entity table has something to draw.
     *
     * Deterministic rather than random: a fixture whose figures move between seeds makes every
     * assertion about them flaky, and the numbers here exist to be pointed at, not to look plausible.
     */
    private function metrics(ExternalAd $ad): void
    {
        $rows = [];

        /*
         * A weight per ad, from the ad's own key.
         *
         * Every ad was given the SAME series, which was invisible while one ad per project carried
         * metrics and became the whole story once the ad-set grain above them was written: thirteen ad
         * sets rendering «1.94K SAR · 126K · 2.52K» to the last digit, under a table whose own subtitle
         * promises «الأعلى إنفاقًا أولًا». A ranking in which every row ties is not a ranking, and the
         * change-drivers list beside it named thirteen equal movers.
         *
         * Derived from the key rather than drawn at random: the figures here exist to be pointed at by
         * assertions, and a fixture whose numbers move between seeds makes every one of them flaky.
         */
        $weight = 0.6 + (crc32((string) $ad->getKey()) % 12) / 10;

        for ($day = 0; $day < self::DAYS; $day++) {
            $date = Carbon::today()->subDays($day);
            $step = $day + 1;

            $rows[] = [
                'id' => (string) Str::uuid(),
                'tenant_id' => $ad->tenant_id,
                'project_id' => $ad->project_id,
                'provider' => $ad->provider,
                'entity_type' => 'ad',
                'entity_id' => $ad->getKey(),
                'external_entity_id' => (string) $ad->external_id,
                'external_campaign_id' => $ad->external_campaign_id,
                'external_ad_set_id' => $ad->external_ad_set_id,
                'metric_date' => $date->toDateString(),
                'attribution_window' => 'default',
                'impressions' => round(1200 * $step * $weight),
                'clicks' => round(24 * $step * $weight),
                'spend' => round(18.5 * $step * $weight, 2),
                'conversions' => max(1, (int) round($step * $weight)),
                'is_demo' => true,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ];
        }

        DB::table('entity_daily_metrics')->insert($rows);
    }

    /**
     * The rung above the ads, summed from the ads themselves.
     *
     * `ChangeDrivers` answers `by=ad_set` out of `entity_daily_metrics` at the `ad_set` grain, and the
     * demo world wrote only the `ad` grain — so that drill had nothing to answer with and returned an
     * empty dimension, which reads in a browser exactly like a broken one. The rows here are the SUM of
     * each ad set's own ads for the same day, not an independent invention: a provider's ad-set total
     * and the ads under it have to reconcile, and a fixture that made them disagree would teach the
     * wrong thing about the grain. Ad sets whose ads carry no metrics stay empty for the same reason.
     *
     * Written only for ad sets that have none, so a second run adds nothing.
     */
    private function adSetMetrics(): int
    {
        $already = EntityDailyMetric::withoutGlobalScopes()
            ->where('entity_type', EntityDailyMetric::AD_SET)
            ->distinct()
            ->pluck('entity_id')
            ->all();

        $sums = DB::table('entity_daily_metrics')
            ->select([
                'tenant_id', 'project_id', 'provider', 'external_ad_set_id', 'metric_date',
                DB::raw('SUM(impressions) AS impressions'),
                DB::raw('SUM(clicks) AS clicks'),
                DB::raw('SUM(spend) AS spend'),
                DB::raw('SUM(conversions) AS conversions'),
            ])
            ->where('entity_type', EntityDailyMetric::AD)
            ->whereNotNull('external_ad_set_id')
            ->whereNotIn('external_ad_set_id', $already)
            ->groupBy('tenant_id', 'project_id', 'provider', 'external_ad_set_id', 'metric_date')
            ->get();

        if ($sums->isEmpty()) {
            return 0;
        }

        $externalIds = ExternalAdSet::withoutGlobalScopes()
            ->whereIn('id', $sums->pluck('external_ad_set_id')->unique()->all())
            ->pluck('external_id', 'id')
            ->all();

        $rows = [];

        foreach ($sums as $sum) {
            $externalId = $externalIds[$sum->external_ad_set_id] ?? null;

            /*
             * No ad set behind the id means the ads point at something the demo world never created,
             * and `external_entity_id` is NOT NULL — inventing one would put a row in the table whose
             * provider key matches nothing.
             */
            if ($externalId === null) {
                continue;
            }

            $rows[] = [
                'id' => (string) Str::uuid(),
                'tenant_id' => $sum->tenant_id,
                'project_id' => $sum->project_id,
                'provider' => $sum->provider,
                'entity_type' => EntityDailyMetric::AD_SET,
                'entity_id' => $sum->external_ad_set_id,
                'external_entity_id' => (string) $externalId,
                'external_ad_set_id' => $sum->external_ad_set_id,
                'metric_date' => $sum->metric_date,
                'attribution_window' => 'default',
                'impressions' => $sum->impressions,
                'clicks' => $sum->clicks,
                'spend' => $sum->spend,
                'conversions' => $sum->conversions,
                'is_demo' => true,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ];
        }

        DB::table('entity_daily_metrics')->insert($rows);

        return count(array_unique(array_column($rows, 'entity_id')));
    }
}

<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Campaigns\Models\ExternalAd;
use App\Domains\Campaigns\Models\ExternalCreative;
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

        $this->command?->info("Demo: {$linked} ads now carry a creative and ad-level metrics.");
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
                'impressions' => 1200 * $step,
                'clicks' => 24 * $step,
                'spend' => 18.5 * $step,
                'conversions' => $step,
                'is_demo' => true,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ];
        }

        DB::table('entity_daily_metrics')->insert($rows);
    }
}

<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Services;

use Illuminate\Support\Facades\DB;

/**
 * CONTENT-RESULT-AVAILABILITY-001 — whether a zero is a measurement or a shrug.
 *
 * ## The question
 *
 * «الطلبات 0» is two completely different claims wearing one number. Either this creative was
 * measured and sold nothing, or nobody is measuring purchases on this account and the platform
 * answered the question anyway. The first belongs on a card. The second is «—».
 *
 * The pipeline is already careful at its edge: a field the response omits or sends as null is
 * skipped by the connector and skipped again by the upsert, so the column stays NULL and reads as
 * «not reported». Nothing here changes that, and it is still the primary signal.
 *
 * The gap is one rung up. `SnapchatConnector::METRICS` asks for `conversion_purchases` on EVERY
 * creative — regardless of what the campaign was bought to do — and Snapchat answers `0` for an
 * account with no purchase measurement configured exactly as it answers `0` for an account that
 * measures purchases and sold none. Both arrive as a non-null zero.
 *
 * ## The evidence, and what is deliberately not evidence
 *
 * The question asked here is whether this project's connection to this PROVIDER has ever recorded a
 * non-zero value for the metric, at any grain, across everything we hold. That is the strongest
 * available statement about whether the provider measures it here at all, and it is conservative in
 * the direction that matters: an account that has ever recorded one order keeps every zero it
 * reports afterwards, including a creative that genuinely sold nothing this week. Only an account
 * that has never recorded one loses them — to «—», which is what «nobody can tell» looks like.
 *
 * Not evidence: that the figure is zero, that the objective looks unusual, or that the window is
 * short. A zero is suspicious, and suspicion is not provenance.
 *
 * ## Why the whole history and not the window
 *
 * A seven-day window containing no sales is the ordinary state of a real account, and treating it
 * as proof that nothing is measured would erase every honest zero in the product. The history is
 * the only span long enough to separate «quiet» from «not wired up».
 */
final class CreativeResultAvailability
{
    /**
     * The RESULT metrics a provider can answer with a fabricated zero, and where each is stored.
     *
     * Delivery metrics are deliberately absent. Impressions and clicks are reported by every
     * provider on every account, so a zero in one of them is a fact about the ad rather than about
     * the account's measurement — and `creative_daily_metrics` stores those two as NOT NULL anyway,
     * which is a separate truth problem and not this one.
     *
     * @var array<string, array{creative?:string, entity?:string}>
     */
    private const RESULTS = [
        // `orders` is the label the product shows; `conversions` is the column it is stored in.
        'orders' => ['creative' => 'conversions', 'entity' => 'conversions'],
        'conversions' => ['creative' => 'conversions', 'entity' => 'conversions'],
        'purchases' => ['creative' => 'purchases', 'entity' => 'purchases'],
        'add_to_cart' => ['creative' => 'add_to_cart', 'entity' => 'add_to_cart'],
        'checkout' => ['creative' => 'checkout', 'entity' => 'checkout'],
        'landing_page_views' => ['creative' => 'landing_page_views', 'entity' => 'landing_page_views'],
        'engagements' => ['creative' => 'engagements', 'entity' => 'engagements'],
        'leads' => ['entity' => 'leads'],
        'sign_ups' => ['entity' => 'sign_ups'],
        'installs' => ['entity' => 'installs'],
        'app_opens' => ['entity' => 'app_opens'],
        'page_views' => ['entity' => 'page_views'],
    ];

    /** Metric keys this service has an opinion about at all. */
    public static function keys(): array
    {
        return array_keys(self::RESULTS);
    }

    /**
     * Which results this project has ever measured through this provider.
     *
     * @return array<string, bool> metric key => a non-zero has been recorded at some point
     */
    public function measured(string $projectId, string $provider): array
    {
        $creative = $this->everNonZero(
            DB::table('creative_daily_metrics')
                ->join('external_creatives', 'external_creatives.id', '=', 'creative_daily_metrics.creative_id')
                ->where('creative_daily_metrics.project_id', $projectId)
                ->where('external_creatives.provider', $provider),
            'creative_daily_metrics',
            'creative',
        );

        $entity = $this->everNonZero(
            DB::table('entity_daily_metrics')
                ->where('project_id', $projectId)
                ->where('provider', $provider),
            'entity_daily_metrics',
            'entity',
        );

        $out = [];

        foreach (self::RESULTS as $key => $_columns) {
            $out[$key] = ($creative[$key] ?? false) || ($entity[$key] ?? false);
        }

        return $out;
    }

    /**
     * One aggregate per table rather than one per metric.
     *
     * `MAX(ABS(col))` over the whole history answers «was this ever anything but zero or null» for
     * every column in a single pass. A query per metric would be a dozen table scans to answer one
     * question, on a page that is already asking for a library.
     *
     * @return array<string, bool>
     */
    private function everNonZero(\Illuminate\Database\Query\Builder $query, string $table, string $grain): array
    {
        $selects = [];
        $keys = [];

        foreach (self::RESULTS as $key => $columns) {
            $column = $columns[$grain] ?? null;

            if ($column === null || isset($keys[$column])) {
                continue;
            }

            $keys[$column] = true;
            $selects[] = "MAX(ABS({$table}.{$column})) AS m_{$column}";
        }

        if ($selects === []) {
            return [];
        }

        $row = (array) ($query->selectRaw(implode(', ', $selects))->first() ?? []);

        $out = [];

        foreach (self::RESULTS as $key => $columns) {
            $column = $columns[$grain] ?? null;
            $out[$key] = $column !== null && ((float) ($row["m_{$column}"] ?? 0)) > 0.0;
        }

        return $out;
    }
}

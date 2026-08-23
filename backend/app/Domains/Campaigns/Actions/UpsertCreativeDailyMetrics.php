<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Actions;

use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Metrics\Services\ReportingCurrency;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * SNAP-CREATIVE-METRICS-001 — provider creative rows become `creative_daily_metrics`.
 *
 * ## Why this table and not `daily_metrics`
 *
 * `daily_metrics` is keyed `(external_account_id, external_campaign_id, metric_key, metric_date,
 * attribution_window)` with `external_campaign_id` NOT NULL. There is no column for a creative and
 * no honest way to invent one. `creative_daily_metrics` has existed since `2026_07_27_120000`,
 * complete and empty, waiting for exactly these rows.
 *
 * ## A creative we have not discovered is SKIPPED, never created
 *
 * The structure sweep owns creative identity; this action owns numbers. A stats row naming a
 * creative the sweep has not seen is counted as skipped and dropped — inventing an `ExternalCreative`
 * here would produce a row with a provider id and nothing else: no name, no format, no ad, no
 * campaign. That is a placeholder wearing a creative's clothes, and the content library would show
 * it as real.
 *
 * ## Idempotent by the table's own key
 *
 * `unique(creative_id, metric_date)` makes the upsert safe to repeat, so a re-sync of an overlapping
 * window corrects figures in place rather than doubling them — which matters because attribution
 * keeps moving for days after the fact.
 */
final class UpsertCreativeDailyMetrics
{
    /**
     * CREATIVE-MONEY-TRUTH-001 — the same FX rule the rest of the pipeline obeys.
     *
     * `daily_metrics` reaches its table through `InsightRowNormaliser`, which converts money into
     * the project's reporting currency and WITHHOLDS it when no rate can be vouched for. These rows
     * bypassed that entirely: `AccountMetricsSyncer` calls this action with the connector's output
     * directly. Reusing the same service is what makes it one rule rather than two.
     */
    public function __construct(private readonly ReportingCurrency $currency) {}

    /** Columns the provider row can carry, in the shape `pointToRow()` produces. */
    private const MEASURES = [
        'spend', 'impressions', 'clicks', 'conversions', 'revenue', 'video_views', 'video_completions',
    ];

    /**
     * The money columns THIS TABLE has, and the reason they are named here as well as catalogued.
     *
     * `metric_definitions.is_currency` is the product's one answer to «is this money», and it stays
     * the authority — a metric catalogued as money later is converted here with no change. But an
     * empty or unseeded catalogue would make `isMonetary()` answer false for everything, and the
     * failure mode of that is the exact defect this class was fixed for: an unconverted figure
     * stored as though it were already in the project's currency.
     *
     * So the two are OR'd. The catalogue can only ever widen what counts as money here, never narrow
     * it below the two columns the schema itself calls money.
     */
    private const MONEY = ['spend', 'revenue'];

    /**
     * @param  list<array<string,mixed>>  $rows  canonical rows; the provider creative id is in `campaign_id`
     * @return array{upserted:int,skipped:int,ambiguous:int}
     */
    public function execute(ExternalAccount $account, array $rows): array
    {
        if ($rows === []) {
            return ['upserted' => 0, 'skipped' => 0, 'ambiguous' => 0];
        }

        /*
         * Resolution is SCOPED, and ambiguity fails closed — CREATIVE-ACCOUNT-IDENTITY-001.
         *
         * `external_creatives` is unique on `(project_id, provider, external_creative_id)` and holds
         * no account column, while a project may bind several accounts of one provider. Matching on
         * `provider + external_creative_id` alone would therefore let one account's stats land on
         * another account's creative — silently, and only in the projects where it matters.
         *
         * The account is reached through the canonical relation that does exist: a creative names its
         * external campaign, and a campaign names its account. Scoping through it means a row can
         * only ever be written to a creative that belongs to the account the stats came from.
         *
         * If a provider creative id still resolves to more than one row inside that scope, nothing
         * is written for it. Picking one would be a coin toss recorded as a measurement.
         */
        $ids = array_values(array_unique(array_filter(
            array_map(static fn (array $r): string => (string) ($r['campaign_id'] ?? ''), $rows),
        )));

        $scoped = ExternalCreative::withoutGlobalScopes()
            ->where('provider', $account->provider)
            ->where('tenant_id', $account->tenant_id)
            ->whereIn('external_creative_id', $ids)
            ->whereIn('external_campaign_id', ExternalCampaign::withoutGlobalScopes()
                ->where('external_account_id', $account->getKey())
                ->select('id'))
            ->get(['id', 'tenant_id', 'project_id', 'campaign_id', 'external_creative_id']);

        $byProviderId = [];
        $ambiguous = [];

        foreach ($scoped as $creative) {
            $key = (string) $creative->external_creative_id;

            if (isset($byProviderId[$key])) {
                $ambiguous[$key] = true;

                continue;
            }

            $byProviderId[$key] = $creative;
        }

        // An id that resolved more than once resolves to nothing.
        foreach (array_keys($ambiguous) as $key) {
            unset($byProviderId[$key]);
        }

        $upserted = 0;
        $skipped = 0;
        $payload = [];
        $now = Carbon::now();

        foreach ($rows as $row) {
            $creative = $byProviderId[(string) ($row['campaign_id'] ?? '')] ?? null;
            $date = (string) ($row['date'] ?? '');

            if ($creative === null || $date === '') {
                $skipped++;

                continue;
            }

            /*
             * The currency this row's money is actually IN — read exactly as `InsightRowNormaliser`
             * reads it.
             *
             * Snapchat states the currency on the ACCOUNT, not on the stats row, which is why the
             * account is the fallback and the reporting currency is only the last resort. Letting
             * the reporting currency stand in unconditionally is precisely the bug: it treats an
             * unlabelled figure as already converted.
             */
            $reporting = $this->currency->forProject((string) $creative->project_id);
            $source = strtoupper((string) ($row['currency'] ?? $account->currency ?? $reporting));

            $measures = [];
            $money = [];
            $delivered = false;

            foreach (self::MEASURES as $key) {
                /*
                 * ABSENT stays absent.
                 *
                 * A platform that does not report video completions has not reported zero of them,
                 * and writing 0 here would turn «not measured» into «measured as none» on a card
                 * that reads the two differently.
                 */
                if (! array_key_exists($key, $row) || $row[$key] === null) {
                    continue;
                }

                if (! in_array($key, self::MONEY, true) && ! $this->currency->isMonetary($key)) {
                    $measures[$key] = $row[$key];

                    continue;
                }

                /*
                 * Money is converted; counts are not. `metric_definitions.is_currency` decides, so a
                 * metric catalogued as money later needs no change here.
                 */
                $amount = (float) $row[$key];
                $converted = $this->currency->normalise($amount, $source, $reporting, Carbon::parse($date));

                // Null when no rate could be vouched for. NOT zero, and NOT the unconverted figure
                // wearing the project's currency — which is what this table used to store.
                $measures[$key] = $converted['value'];
                $money["{$key}_original"] = $amount;
                $money['original_currency'] = $source;
                $money['project_currency'] = $reporting;

                // A day whose money was withheld is still a day the creative ran, so delivery is
                // judged on the amount the platform reported, never on the withheld null.
                $delivered = $delivered || $amount > 0;
            }

            $payload[] = [
                'id' => (string) Str::uuid(),
                'tenant_id' => $creative->tenant_id,
                'project_id' => $creative->project_id,
                'creative_id' => $creative->id,
                'campaign_id' => $creative->campaign_id,
                'metric_date' => $date,
                'is_demo' => false,
                'created_at' => $now,
                'updated_at' => $now,
                ...$measures,
                ...$money,
            ];
            $upserted++;
        }

        foreach (array_chunk($payload, 500) as $chunk) {
            DB::table('creative_daily_metrics')->upsert(
                $chunk,
                ['creative_id', 'metric_date'],
                [
                    ...self::MEASURES,
                    // CREATIVE-MONEY-TRUTH-001 — a re-sync that finds a rate must be able to replace a
                    // withheld null with the converted figure, so these update in place too.
                    'spend_original', 'revenue_original', 'original_currency', 'project_currency',
                    'campaign_id', 'updated_at',
                ],
            );
        }

        $this->recordDelivery(array_values(array_unique(array_column($payload, 'creative_id'))));

        return ['upserted' => $upserted, 'skipped' => $skipped, 'ambiguous' => count($ambiguous)];
    }

    /**
     * SNAP-CREATIVE-METRICS-LIVE-001 — `last_active_at` is a fact, and nothing was writing it.
     *
     * ## What was actually wrong with the Creative Library
     *
     * The library's default order is `last_active_at DESC, last_synced_at DESC, id`. On production
     * NOTHING in the sync pipeline had ever written `last_active_at` — the only writer in the
     * codebase was the demo seeder — so it was NULL for all 1,451 live creatives. The second key
     * then tied for every one of them, because a structure sweep touches the whole account in one
     * run. The order collapsed to `id`, and the 86 creatives that had actually delivered were
     * scattered across sixty-one pages of twenty-four.
     *
     * The numbers were there. The page that shows them opened on the creatives that had none, so
     * the library read as empty while `creative_daily_metrics` held 814 rows.
     *
     * ## «Active» means it DELIVERED, not that we asked about it
     *
     * A stats call returns a row for a creative that spent nothing, and a date on such a row says
     * only that we requested that day. So delivery is `impressions > 0 OR spend > 0` — spend is
     * kept beside impressions because a withheld-currency row is still a day the creative ran, and
     * because a conversions-only integration can report cost with no impression column at all.
     *
     * A creative with no delivering day keeps its NULL: it has not «been active a long time ago»,
     * we simply have no day on which it ran.
     *
     * @param  list<string>  $creativeIds
     */
    private function recordDelivery(array $creativeIds): void
    {
        foreach (array_chunk($creativeIds, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));

            /*
             * Recomputed from the whole table, not from this batch — a re-sync of an older window
             * must not pull a creative's last delivery backwards, and MAX over every stored day is
             * the answer regardless of which window brought us here.
             */
            DB::update(
                "UPDATE external_creatives AS c
                    SET last_active_at = d.last_delivery
                   FROM (
                        SELECT creative_id, MAX(metric_date)::timestamp AS last_delivery
                          FROM creative_daily_metrics
                         WHERE creative_id IN ({$placeholders})
                           /*
                            * `spend_original`, not `spend` — CREATIVE-MONEY-TRUTH-001.
                            *
                            * `spend` is NULL when the day's money could not be converted, and a
                            * withheld figure is still a day the creative ran. Reading the converted
                            * column here would make every creative on an unquoted currency look as
                            * though it had never delivered.
                            */
                           AND (impressions > 0 OR COALESCE(spend, spend_original, 0) > 0)
                      GROUP BY creative_id
                   ) AS d
                  WHERE c.id = d.creative_id
                    AND (c.last_active_at IS NULL OR c.last_active_at IS DISTINCT FROM d.last_delivery)",
                $chunk,
            );
        }
    }
}

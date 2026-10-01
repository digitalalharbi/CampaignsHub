<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Services;

use App\Domains\Campaigns\Enums\ResultAvailability;
use App\Domains\Integrations\Services\BoundAccountVisibility;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * CONTENT-RESULT-AVAILABILITY-001 — whether a zero is a measurement, a shrug, or somebody else's.
 *
 * ## The question
 *
 * «الطلبات 0» is two completely different claims wearing one number. Either this creative was
 * measured and sold nothing, or nobody is measuring purchases on this account and the platform
 * answered the question anyway. The first belongs on a card. The second does not.
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
 * ## What this knows, and what it refuses to claim
 *
 * Evidence that a metric IS measurable is positive and usable: an account that has recorded a
 * non-zero for it has demonstrably measured it, and its later zeros are real. That is the only
 * claim this service makes.
 *
 * The ABSENCE of that evidence proves nothing, and the first version of this treated it as proof.
 * A new sales account with a correctly installed pixel and no sales yet is indistinguishable, in
 * stored data, from an account with no pixel at all — so «never observed a non-zero» cannot mean
 * «cannot measure». It means nobody has verified it, which is a state of its own.
 *
 * ## The scope is the EXACT account
 *
 * The first version asked `project_id + provider`, which is the set of accounts a reader may see
 * rather than the account that served the ad. A project routinely holds several ad accounts of one
 * provider — an agency runs a client's own Snapchat account beside its own — and one account's
 * historical purchase cannot certify another's zero. They are two advertisers' measurement setups
 * that happen to be filed together, and lending evidence between them is the same mistake as
 * lending it between tenants, one scope smaller.
 *
 * So the chain is followed to its end: creative → campaign (or its ads' campaigns) → external
 * account. A creative whose figures come from several accounts is confirmed only when EVERY
 * contributing account is.
 *
 * Deliberately NOT evidence: the objective, the figure being zero, the window being short, another
 * account, another project, another tenant. And no provider capability is invented — there is no
 * stored pixel-configuration fact in this system to read, so none is pretended.
 */
final class CreativeResultAvailability
{
    /**
     * The RESULT metrics a provider can answer with a fabricated zero, and where each is stored.
     *
     * Delivery metrics are deliberately absent. Impressions and clicks are reported by every
     * provider on every account, so a zero in one of them is a fact about the ad rather than about
     * the account's measurement.
     *
     * Every result has a CAMPAIGN column, and only some have the two finer grains — which is the
     * shape of the data rather than an accident: `daily_metrics` is the one table every provider
     * writes to, and the ad and creative grains are what each connector additionally supports.
     *
     * @var array<string, array{creative?:string, entity?:string, campaign:string}>
     */
    private const RESULTS = [
        // `orders` is the label the product shows; `conversions` is the column it is stored in.
        'orders' => ['creative' => 'conversions', 'entity' => 'conversions', 'campaign' => 'conversions'],
        'conversions' => ['creative' => 'conversions', 'entity' => 'conversions', 'campaign' => 'conversions'],
        'purchases' => ['creative' => 'purchases', 'entity' => 'purchases', 'campaign' => 'purchases'],
        'add_to_cart' => ['creative' => 'add_to_cart', 'entity' => 'add_to_cart', 'campaign' => 'add_to_cart'],
        'checkout' => ['creative' => 'checkout', 'entity' => 'checkout', 'campaign' => 'checkout'],
        'landing_page_views' => ['creative' => 'landing_page_views', 'entity' => 'landing_page_views', 'campaign' => 'landing_page_views'],
        'engagements' => ['creative' => 'engagements', 'entity' => 'engagements', 'campaign' => 'engagements'],
        'leads' => ['entity' => 'leads', 'campaign' => 'leads'],
        'sign_ups' => ['entity' => 'sign_ups', 'campaign' => 'sign_ups'],
        'installs' => ['entity' => 'installs', 'campaign' => 'installs'],
        'app_opens' => ['entity' => 'app_opens', 'campaign' => 'app_opens'],
        'page_views' => ['entity' => 'page_views', 'campaign' => 'page_views'],
    ];

    /** Metric keys this service has an opinion about at all. */
    public static function keys(): array
    {
        return array_keys(self::RESULTS);
    }

    /**
     * The exact accounts that served each creative — the chain, followed to its end.
     *
     * Both halves matter. A creative names the campaign it was synced under, and its ADS name
     * theirs: a creative reused across two campaigns on two accounts draws its figures from both,
     * and a rule that read only the first would certify half a sum with one account's evidence.
     *
     * @param  list<string>  $creativeIds
     * @return array<string, list<string>> creative id => the account ids behind it
     */
    public function accountsFor(array $creativeIds): array
    {
        if ($creativeIds === []) {
            return [];
        }

        $out = [];

        $own = DB::table('external_creatives')
            ->join('external_campaigns', 'external_campaigns.id', '=', 'external_creatives.external_campaign_id')
            ->whereIn('external_creatives.id', $creativeIds)
            ->get(['external_creatives.id as creative_id', 'external_campaigns.external_account_id as account_id']);

        $throughAds = DB::table('external_ads')
            ->join('external_campaigns', 'external_campaigns.id', '=', 'external_ads.external_campaign_id')
            ->whereIn('external_ads.creative_id', $creativeIds)
            ->get(['external_ads.creative_id as creative_id', 'external_campaigns.external_account_id as account_id']);

        foreach ([$own, $throughAds] as $rows) {
            foreach ($rows as $row) {
                $account = (string) ($row->account_id ?? '');

                if ($account === '') {
                    continue;
                }

                $out[(string) $row->creative_id][$account] = true;
            }
        }

        return array_map(static fn (array $set): array => array_keys($set), $out);
    }

    /**
     * Which results each of these accounts has DEMONSTRABLY measured, at any time, at any grain.
     *
     * One pass per grain rather than per metric: `MAX(ABS(col))` over the whole history answers «was
     * this ever anything but zero or null» for every column at once, and a query per metric would be
     * a dozen table scans to answer one question on a page that is already asking for a library.
     *
     * @param  list<string>  $accountIds
     * @return array<string, array<string, bool>> account id => metric key => proved measurable
     */
    public function measuredByAccount(array $accountIds): array
    {
        if ($accountIds === []) {
            return [];
        }

        $out = array_fill_keys($accountIds, array_fill_keys(self::keys(), false));

        $this->foldWide(
            $out,
            DB::table('creative_daily_metrics')
                ->join('external_creatives', 'external_creatives.id', '=', 'creative_daily_metrics.creative_id')
                ->join('external_campaigns', 'external_campaigns.id', '=', 'external_creatives.external_campaign_id')
                ->whereIn('external_campaigns.external_account_id', $accountIds)
                /*
                 * No demo filter, and that is the scoping doing its job rather than an omission.
                 *
                 * Demo rows are seeded under demo accounts, so an exact-account question already
                 * keeps them to themselves: a demo account's rows certify that demo account and
                 * nothing else, and a real account cannot be certified by a world that was invented.
                 * Filtering `is_demo` here instead would leave the demo library unable to confirm
                 * any of its own zeros, which is a different kind of lie.
                 */
                ->tap(fn ($q) => BoundAccountVisibility::applyThroughCampaign(
                    $q,
                    'external_creatives.external_campaign_id',
                    'creative_daily_metrics.project_id',
                ))
                ->groupBy('external_campaigns.external_account_id'),
            'external_campaigns.external_account_id',
            'creative_daily_metrics',
            'creative',
        );

        $this->foldWide(
            $out,
            DB::table('entity_daily_metrics')
                ->whereIn('entity_daily_metrics.external_account_id', $accountIds)
                ->tap(fn ($q) => BoundAccountVisibility::applyToEntityMetrics($q, 'entity_daily_metrics'))
                ->groupBy('entity_daily_metrics.external_account_id'),
            'entity_daily_metrics.external_account_id',
            'entity_daily_metrics',
            'entity',
        );

        $this->foldTall($out, $accountIds, null, null);

        return $out;
    }

    /**
     * Which results each account recorded IN THIS PERIOD, above the creative grain.
     *
     * The evidence behind «the figure exists and is not this creative's». It is the same question as
     * above with a window on it and the creative grain left out: a number the campaign or its ads
     * recorded, which this creative's own rows do not account for.
     *
     * @param  list<string>  $accountIds
     * @return array<string, array<string, bool>>
     */
    public function recordedInPeriod(array $accountIds, Carbon $from, Carbon $to): array
    {
        if ($accountIds === []) {
            return [];
        }

        $out = array_fill_keys($accountIds, array_fill_keys(self::keys(), false));

        $this->foldWide(
            $out,
            DB::table('entity_daily_metrics')
                ->whereIn('entity_daily_metrics.external_account_id', $accountIds)
                ->whereBetween('entity_daily_metrics.metric_date', [$from->toDateString(), $to->toDateString()])
                ->tap(fn ($q) => BoundAccountVisibility::applyToEntityMetrics($q, 'entity_daily_metrics'))
                ->groupBy('entity_daily_metrics.external_account_id'),
            'entity_daily_metrics.external_account_id',
            'entity_daily_metrics',
            'entity',
        );

        $this->foldTall($out, $accountIds, $from, $to);

        return $out;
    }

    /**
     * A wide metrics table — one column per metric — folded into the per-account map.
     *
     * @param  array<string, array<string, bool>>  $out
     */
    private function foldWide(array &$out, Builder $query, string $accountColumn, string $table, string $grain): void
    {
        $selects = ["{$accountColumn} as account_id"];
        $seen = [];

        foreach (self::RESULTS as $columns) {
            $column = $columns[$grain] ?? null;

            if ($column === null || isset($seen[$column])) {
                continue;
            }

            $seen[$column] = true;
            $selects[] = "MAX(ABS({$table}.{$column})) AS m_{$column}";
        }

        foreach ($query->selectRaw(implode(', ', $selects))->get() as $row) {
            $account = (string) $row->account_id;

            foreach (self::RESULTS as $key => $columns) {
                $column = $columns[$grain] ?? null;

                if ($column === null) {
                    continue;
                }

                $out[$account][$key] = ($out[$account][$key] ?? false)
                    || ((float) (((array) $row)["m_{$column}"] ?? 0)) > 0.0;
            }
        }
    }

    /**
     * `daily_metrics` is key/value rather than one column per metric, so it folds differently.
     *
     * Worth reading at all because it is the CAMPAIGN grain: an account that reports purchases only
     * at campaign level has still demonstrably measured them, and refusing that evidence would call
     * a perfectly instrumented account unverified.
     *
     * @param  array<string, array<string, bool>>  $out
     * @param  list<string>  $accountIds
     */
    private function foldTall(array &$out, array $accountIds, ?Carbon $from, ?Carbon $to): void
    {
        $wanted = [];

        foreach (self::RESULTS as $key => $columns) {
            $wanted[$columns['campaign']][] = $key;
        }

        $rows = DB::table('daily_metrics')
            ->whereIn('daily_metrics.external_account_id', $accountIds)
            ->whereIn('daily_metrics.metric_key', array_keys($wanted))
            ->when($from !== null && $to !== null, fn ($q) => $q->whereBetween(
                'daily_metrics.metric_date',
                [$from->toDateString(), $to->toDateString()],
            ))
            ->tap(fn ($q) => BoundAccountVisibility::apply($q, 'daily_metrics'))
            ->groupBy('daily_metrics.external_account_id', 'daily_metrics.metric_key')
            ->selectRaw('daily_metrics.external_account_id as account_id, daily_metrics.metric_key as metric_key, MAX(ABS(daily_metrics.value)) as peak')
            ->get();

        foreach ($rows as $row) {
            if (((float) $row->peak) <= 0.0) {
                continue;
            }

            foreach ($wanted[(string) $row->metric_key] ?? [] as $key) {
                $out[(string) $row->account_id][$key] = true;
            }
        }
    }

    /**
     * The verdict for ONE metric on one creative — the whole rule, in one place.
     *
     * @param  list<string>  $accounts  every account this creative's figures come from
     * @param  array<string, array<string, bool>>  $measured  account => metric => proved measurable
     * @param  array<string, array<string, bool>>  $inPeriod  account => metric => recorded above the creative grain
     */
    public function verdict(
        string $key,
        float|int|null $value,
        bool $reported,
        array $accounts,
        array $measured,
        array $inPeriod,
    ): ResultAvailability {
        if ($reported && $value !== null && (float) $value !== 0.0) {
            return ResultAvailability::ReportedValue;
        }

        if (! $reported || $value === null) {
            /*
             * Nothing arrived for this creative — so the question becomes whether the figure exists
             * at all. A campaign that recorded purchases this week, on this account, over ads this
             * creative's own rows do not account for, is a real number that is not this creative's:
             * «0» would be a lie about the ad and «the platform does not send this» a lie about the
             * account.
             */
            foreach ($accounts as $account) {
                if (($inPeriod[$account][$key] ?? false) === true) {
                    return ResultAvailability::NotAttributable;
                }
            }

            return ResultAvailability::NotReported;
        }

        /*
         * A zero, and the only thing that can make it a FIGURE is this account having measured the
         * metric before. Every contributing account must have: a sum across two accounts, one of
         * which has never proved it measures purchases, is not a confirmed zero for either of them.
         *
         * A creative with no account behind it at all — unlinked, or its campaign removed — has no
         * evidence by construction, and «unverified» is exactly what that is.
         */
        if ($accounts === []) {
            return ResultAvailability::MeasurementUnverified;
        }

        foreach ($accounts as $account) {
            if (($measured[$account][$key] ?? false) !== true) {
                return ResultAvailability::MeasurementUnverified;
            }
        }

        return ResultAvailability::RealZeroConfirmed;
    }
}

<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Services;

use App\Domains\Campaigns\Enums\CampaignObjective;
use App\Domains\Campaigns\Enums\MarketingPath;
use App\Domains\Campaigns\Enums\ObjectiveFamily;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What a creative's numbers mean, and which of them the platform actually sent (§15.4, §15.5, §15.15).
 *
 * ## The two rules this class exists to keep
 *
 * **1. A metric nobody reported is not zero.** `creative_daily_metrics` stores video columns as NULL
 * when the provider does not report them, and this class carries that distinction all the way to the
 * response as `null` plus a `reported` map. A completion rate of 0% beside 40,000 impressions reads
 * as a catastrophic video; «Not provided» reads as what actually happened. SQL makes this easy to get
 * wrong — `SUM()` over all-NULL is NULL, but `COALESCE(SUM(x), 0)` silently invents the zero — so the
 * sums here are deliberately un-coalesced and the null is preserved.
 *
 * **2. A creative is judged by its campaign's objective.** An awareness video has no CPA, and
 * printing one for it is not a harmless extra column: it is a terrible number attached to content
 * that was never asked to sell, and it is what makes somebody switch off the top of their funnel.
 * `headline()` returns the metrics that mean something for the creative's marketing path, and
 * `comparable()` refuses to rank two creatives on one axis when they are doing different jobs.
 */
final class CreativeMetrics
{
    /**
     * Every column summed per creative. Keys are the response's own names.
     *
     * NOT wrapped in COALESCE: a null sum is the answer when the provider reported nothing, and this
     * is the one place in the aggregation where losing that distinction costs the reader the truth.
     */
    /**
     * Every ratio {@see self::derive()} computes — the other half of what this service can supply.
     *
     * Named rather than inferred because `supportable()` has to know the difference between «this
     * table has no such column» and «this is computed from columns it does have».
     *
     * @var list<string>
     */
    private const DERIVED = [
        'ctr', 'cpc', 'cpm', 'cpa', 'roas', 'conversion_rate', 'aov', 'cost_per_view',
        'view_rate', 'completion_rate', 'hook_rate', 'cost_per_lpv', 'engagement_rate',
        'cpe', 'orders',
    ];

    /**
     * Averaged COLUMNS, which are not derived — carried over from CONTENT-KPI-COLLAPSE-001.
     *
     * `frequency` and `video_avg_watch_seconds` sat in {@see self::DERIVED}, and nothing in
     * {@see self::derive()} has ever computed either. They are columns, read as an AVG rather than a
     * SUM because a frequency added across days grows with the length of the window and means nothing.
     *
     * The misfiling still matters even now that selection is availability-aware. `DERIVED` is the
     * PIPELINE filter's answer to «can this service produce the metric at all», and for these two the
     * honest answer is «only if a provider sends the column». Snapchat's creative-grain stats call
     * does not ask for frequency, so the awareness family — most of this account — was promising a
     * cell that could never be filled. Availability now catches that per row; the classification is
     * corrected so the SHAPE is right when no row is in hand.
     *
     * Deriving frequency instead was considered and refused: it is impressions ÷ reach, and `reach`
     * is summed across days, so daily uniques added together over-count the people actually reached
     * and the quotient would be a lower bound presented as a measurement.
     *
     * Both stay in the payload — `shape()` still reads them — so a provider that DOES report them at
     * creative grain is not thrown away. What stops is the headline PROMISING them.
     *
     * @var list<string>
     */
    private const AVERAGED = ['frequency', 'video_avg_watch_seconds'];

    /**
     * How many headline metrics a card is entitled to before it reads as broken.
     *
     * The grid renders `headline_metrics.slice(0, 4)`, so four is not a preference — it is the number
     * the surface asks for, and a family that can answer fewer leaves visible gaps.
     */
    private const HEADLINE_MINIMUM = 4;

    /**
     * The keys whose absence may still be answerable, because FX-001 preserves an original beside a
     * figure it refused to convert.
     *
     * @var list<string>
     */
    private const MONEY = ['spend', 'revenue'];

    private const SUMS = [
        'spend' => 'spend',
        'impressions' => 'impressions',
        'clicks' => 'clicks',
        'conversions' => 'conversions',
        'revenue' => 'revenue',
        'add_to_cart' => 'add_to_cart',
        'checkout' => 'checkout',
        'purchases' => 'purchases',
        'landing_page_views' => 'landing_page_views',
        'engagements' => 'engagements',
        'reach' => 'reach',
        'video_views' => 'video_views',
        'video_views_2s' => 'video_views_2s',
        'video_views_3s' => 'video_views_3s',
        'video_views_6s' => 'video_views_6s',
        'video_p25' => 'video_p25',
        'video_p50' => 'video_p50',
        'video_p75' => 'video_p75',
        'video_p100' => 'video_p100',
        'video_completions' => 'video_completions',
    ];

    /**
     * CREATIVE-MONEY-TRUTH-001 — the withheld half of the money, in the contract's own field names.
     *
     * These are deliberately the SAME keys `MetricsAggregator::MONEY_TRUTH` emits, because the
     * frontend has one money reader (`lib/money/contract.ts`) and it keys off these names. Matching
     * them is what lets a creative card and a dashboard KPI render an unconvertible figure the same
     * way without a second implementation — the thing that «one contract» was supposed to mean.
     *
     * A row is withheld when its converted value is null AND an original survives. A row the platform
     * never reported has neither, and must not be counted here: «not reported» and «reported but not
     * convertible» are different sentences and the card says different things for them.
     */
    private const MONEY_TRUTH = [
        'spend_withheld_rows' => 'COUNT(*) FILTER (WHERE spend IS NULL AND spend_original IS NOT NULL)',
        'spend_original' => 'SUM(spend_original) FILTER (WHERE spend IS NULL AND spend_original IS NOT NULL)',
        'revenue_withheld_rows' => 'COUNT(*) FILTER (WHERE revenue IS NULL AND revenue_original IS NOT NULL)',
        'revenue_original' => 'SUM(revenue_original) FILTER (WHERE revenue IS NULL AND revenue_original IS NOT NULL)',
        'money_original_currency' => 'MIN(original_currency) FILTER (WHERE (spend IS NULL AND spend_original IS NOT NULL) OR (revenue IS NULL AND revenue_original IS NOT NULL))',
        // The reader refuses to name a currency when this is not exactly 1: several unconvertible
        // currencies cannot be added, and printing one of their names would be a wrong label.
        'money_original_currencies' => 'COUNT(DISTINCT original_currency) FILTER (WHERE (spend IS NULL AND spend_original IS NOT NULL) OR (revenue IS NULL AND revenue_original IS NOT NULL))',
    ];

    /**
     * Totals per creative for a window, with derived KPIs and a map of what was actually reported.
     *
     * @param  list<string>  $creativeIds
     * @return array<string, array<string, mixed>> creative id => figures
     */
    public function forCreatives(array $creativeIds, Carbon $from, Carbon $to): array
    {
        if ($creativeIds === []) {
            return [];
        }

        $select = ['creative_id'];
        foreach (self::SUMS as $alias => $column) {
            $select[] = "SUM({$column}) AS {$alias}";
        }
        // Frequency is an average of a ratio, not a sum: adding daily frequencies would produce a
        // number that grows with the length of the window and means nothing.
        $select[] = 'AVG(frequency) AS frequency';
        $select[] = 'AVG(video_avg_watch_seconds) AS video_avg_watch_seconds';
        $select[] = 'COUNT(DISTINCT metric_date) AS active_days';

        foreach (self::MONEY_TRUTH as $alias => $expression) {
            $select[] = "{$expression} AS {$alias}";
        }

        $rows = DB::table('creative_daily_metrics')
            ->whereIn('creative_id', $creativeIds)
            ->whereBetween('metric_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('creative_id')
            ->selectRaw(implode(', ', $select))
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row->creative_id] = $this->shape((array) $row);
        }

        return $out;
    }

    /**
     * One row's figures: the raw sums, the derived KPIs, and what the provider actually reported.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function shape(array $row): array
    {
        $num = static fn (string $key): ?float => $row[$key] === null ? null : (float) $row[$key];

        $figures = [];
        foreach (array_keys(self::SUMS) as $key) {
            $figures[$key] = $num($key);
        }

        foreach (self::AVERAGED as $key) {
            $figures[$key] = $num($key);
        }
        $figures['active_days'] = (int) ($row['active_days'] ?? 0);

        $figures = $this->derive($figures);

        /*
         * What the platform actually sent.
         *
         * The frontend needs this to tell «0» from «not reported», and it cannot infer it from the
         * value alone: a genuine zero and a missing metric both arrive as falsy in JavaScript.
         */
        $figures['reported'] = [];
        // `orders` shares `conversions`' answer, because it shares its column. Without this it is
        // absent from the map and renders as «no data» rather than «not provided» on an awareness
        // creative, which is the weaker of the two true statements.
        $figures['reported']['orders'] = $row['conversions'] !== null;
        foreach (array_keys(self::SUMS) as $key) {
            $figures['reported'][$key] = $row[$key] !== null;
        }
        foreach (self::AVERAGED as $key) {
            $figures['reported'][$key] = $row[$key] !== null;
        }

        /*
         * Carried AFTER `reported` is built, and never inside it.
         *
         * These describe the money's provenance, not a metric the platform sends. Adding them to the
         * reported map would put «Spend withheld rows» on a card as though it were a figure somebody
         * could act on.
         */
        $figures['spend_withheld_rows'] = (int) ($row['spend_withheld_rows'] ?? 0);
        $figures['revenue_withheld_rows'] = (int) ($row['revenue_withheld_rows'] ?? 0);
        $figures['spend_original'] = $num('spend_original');
        $figures['revenue_original'] = $num('revenue_original');
        $figures['money_original_currency'] = $row['money_original_currency'] === null ? null : (string) $row['money_original_currency'];
        $figures['money_original_currencies'] = (int) ($row['money_original_currencies'] ?? 0);

        return $figures;
    }

    /**
     * The derived KPIs, from raw figures — the ONLY place a ratio in this system is written down.
     *
     * Called for one creative's sums and for a set of creatives summed together. Two copies of this
     * arithmetic is exactly how a dashboard's «image vs video ROAS» ends up disagreeing with the ROAS
     * on the cards it was computed from, so the aggregate does not get its own version: it sums the
     * raw figures and comes through here.
     *
     * @param  array<string, mixed>  $figures  raw sums, nulls intact
     * @return array<string, mixed>
     */
    private function derive(array $figures): array
    {
        $num = static fn (string $key): ?float => is_numeric($figures[$key] ?? null) ? (float) $figures[$key] : null;

        $spend = $num('spend');
        $impressions = $num('impressions');
        $clicks = $num('clicks');
        $conversions = $num('conversions');
        $revenue = $num('revenue');
        $videoViews = $num('video_views');

        // Every one is null when its denominator is missing or zero — a ratio over nothing is «there
        // is nothing to divide», and 0 reads as «it costs nothing».
        $figures['ctr'] = $this->ratio($clicks, $impressions);
        $figures['cpc'] = $this->ratio($spend, $clicks);
        $figures['cpm'] = $impressions ? $this->ratio($spend, $impressions / 1000) : null;
        $figures['cpa'] = $this->ratio($spend, $conversions);
        $figures['roas'] = $this->ratio($revenue, $spend);
        $figures['conversion_rate'] = $this->ratio($conversions, $clicks);
        $figures['aov'] = $this->ratio($revenue, $conversions);
        $figures['cost_per_view'] = $this->ratio($spend, $videoViews);
        $figures['view_rate'] = $this->ratio($videoViews, $impressions);
        $figures['completion_rate'] = $this->ratio($num('video_p100') ?? $num('video_completions'), $videoViews);
        $figures['hook_rate'] = $this->ratio($num('video_views_3s'), $impressions);

        /*
         * The two headline metrics the sales path names but this service never produced.
         *
         * `MarketingPath::headlineMetrics()` asks the conversion path for `orders` and the traffic
         * path for `cost_per_lpv`. Neither key existed here, and an absent key reads as «no data» —
         * so a sales creative with 850 orders showed «Orders: No data» on the row that is supposed to
         * carry its most important figure, and two of the seven sales headlines were dead.
         *
         * `orders` is `conversions` under the name the marketing paths use, kept as an alias rather
         * than renamed: `conversions` is the column, the canonical metric and what every other
         * surface reads, and one concept with two names is better than a rename that leaves half the
         * system pointing at the old one.
         */
        $figures['orders'] = $conversions;
        $figures['cost_per_lpv'] = $this->ratio($spend, $num('landing_page_views'));

        return $figures;
    }

    /**
     * Several creatives' figures summed into one — for «images vs videos», a group, or a path total.
     *
     * ## Null survives addition
     *
     * A key nobody reported stays null rather than becoming 0, exactly as it does for one creative.
     * A key SOME reported sums what was actually sent and says so: an aggregate that quietly treated
     * the silent half as zero would report a completion rate over a denominator missing most of its
     * views, which is a worse lie than «not provided» because it looks like an answer.
     *
     * `active_days` is the maximum rather than the sum — a set of creatives that each ran seven days
     * over the same week was delivering for seven days, not for seventy.
     *
     * @param  list<array<string, mixed>>  $sets  rows from `forCreatives()`
     * @return array<string, mixed>|null null when there is nothing at all to add up
     */
    public function aggregate(array $sets): ?array
    {
        $sets = array_values(array_filter($sets, static fn ($s): bool => is_array($s)));

        if ($sets === []) {
            return null;
        }

        $figures = [];
        $reported = [];

        foreach (array_keys(self::SUMS) as $key) {
            $total = null;
            foreach ($sets as $set) {
                if (is_numeric($set[$key] ?? null)) {
                    $total = ($total ?? 0.0) + (float) $set[$key];
                }
            }
            $figures[$key] = $total;
            $reported[$key] = $total !== null;
        }

        /*
         * Frequency is impression-weighted, because it is an average and averages do not add.
         *
         * A creative shown twice to a hundred thousand people and one shown eight times to two
         * hundred do not average to five: the plain mean lets a rounding error of a creative dominate
         * the figure that is supposed to describe the audience's exposure.
         */
        $figures['frequency'] = $this->weightedMean($sets, 'frequency', 'impressions');
        $figures['video_avg_watch_seconds'] = $this->weightedMean($sets, 'video_avg_watch_seconds', 'video_views');
        $reported['frequency'] = $figures['frequency'] !== null;
        $reported['video_avg_watch_seconds'] = $figures['video_avg_watch_seconds'] !== null;

        $figures['active_days'] = (int) max(array_map(
            static fn (array $s): int => (int) ($s['active_days'] ?? 0),
            $sets,
        ));

        $figures = $this->derive($figures);
        $reported['orders'] = $reported['conversions'];
        $figures['reported'] = $reported;
        $figures['creatives'] = count($sets);

        return $figures;
    }

    /**
     * A mean weighted by the figure that gives each row its size, or null when nothing reported it.
     *
     * Falls back to the plain mean when no weight is available — a weighted mean with no weights is
     * not more accurate than an unweighted one, it is undefined, and returning null there would
     * throw away a figure every row actually reported.
     *
     * @param  list<array<string, mixed>>  $sets
     */
    private function weightedMean(array $sets, string $key, string $weightKey): ?float
    {
        $sum = 0.0;
        $weight = 0.0;
        $plain = [];

        foreach ($sets as $set) {
            if (! is_numeric($set[$key] ?? null)) {
                continue;
            }

            $value = (float) $set[$key];
            $plain[] = $value;

            if (is_numeric($set[$weightKey] ?? null) && (float) $set[$weightKey] > 0) {
                $sum += $value * (float) $set[$weightKey];
                $weight += (float) $set[$weightKey];
            }
        }

        if ($plain === []) {
            return null;
        }

        return $weight > 0
            ? round($sum / $weight, 4)
            : round(array_sum($plain) / count($plain), 4);
    }

    /** A ratio, or null when there is nothing to divide by. Never 0 — see the class note. */
    private function ratio(?float $numerator, ?float $denominator): ?float
    {
        if ($numerator === null || $denominator === null || $denominator == 0.0) {
            return null;
        }

        return round($numerator / $denominator, 4);
    }

    /**
     * The metrics that mean something for this creative, given the job its campaign was doing.
     *
     * Delegates to `MarketingPath::headlineMetrics()` rather than keeping a second list: the report
     * layouts read the same source, and two lists that disagree would mean a creative judged one way
     * on the dashboard and another in the client's report.
     *
     * @return list<string>
     */
    /**
     * @param  array<string, mixed>|null  $figures  this creative's own figures for the window, when
     *                                              known; null asks only what the FAMILY wants
     */
    public function headline(?string $objective, ?array $figures = null): array
    {
        /*
         * OBJECTIVE-AWARE-KPI-001 — chosen by the objective's FAMILY, not by its marketing path.
         *
         * The path has three cases and answers a money question. Using it here meant `Leads` and
         * `AppInstalls` — both on the conversion path — were headlined with `revenue`, `roas` and
         * `aov`: figures a lead-generation or app-install campaign was never bought to produce and
         * the platform will never report for it. The requirement is explicit that a campaign must
         * not be judged by another objective's verdict, and this was the shipping version of that.
         *
         * `pathFor()` is untouched and still governs whose CPA the money lands in.
         */
        $family = $this->familyFor($objective);

        $metrics = $family->headlineMetrics();

        /*
         * Video figures ride along on an awareness buy, because a video's hook and completion matter
         * whether it was bought for reach or for sales — but NOT on the video family, which already
         * leads with them, and not on the others, where they would push the actual verdict down.
         */
        $metrics = $family === ObjectiveFamily::Awareness
            ? array_values(array_unique([...$metrics, 'video_views', 'view_rate', 'completion_rate', 'cost_per_view']))
            : $metrics;

        return $this->supportable($metrics, $figures);
    }

    /**
     * Whether THIS row can answer a given metric — the question `supportable()` cannot ask alone.
     *
     * Three states count as answerable, and the third is the one that has been getting this wrong:
     *
     *   REPORTED  a value came back, including a measured zero. `0 orders` is a fact about a sales
     *             creative and belongs on its card.
     *   DERIVED   a ratio this service computed from figures that were reported. Null when its
     *             denominator was missing, which is «there was nothing to divide», not «zero».
     *   WITHHELD  money with no conversion rate. `value` is null by FX-001's design and the ORIGINAL
     *             amount is preserved beside it, so the cell renders «79.61 USD» and is answerable.
     *             Treating this as unanswerable would drop spend off the card of every creative on an
     *             account with no rate — which is every creative on the account this was found on.
     *
     * @param  array<string, mixed>  $figures
     */
    private function answerable(array $figures, string $key): bool
    {
        if (in_array($key, self::MONEY, true)) {
            return $this->moneyAnswerable($figures, $key);
        }

        return ($figures[$key] ?? null) !== null;
    }

    /**
     * CONTENT-KPI-MONEY-PROVENANCE-001 — money is answerable on PROVENANCE, not on non-nullness.
     *
     * The first version of this asked «is the value or its original non-null», and that is not the
     * money contract. It let `revenue_original = 0` put «0.00 USD» on a sales card as though the
     * platform had reported a monetary zero, when a summed original of zero is not evidence of
     * anything having been reported at all. Production creative `81632089` established a spend of
     * 79.61 USD across 11 withheld rows; it established NO revenue, and the card was about to claim
     * one.
     *
     * A withheld figure is displayable only when every part of the claim it makes is true:
     *
     *   · the converted value is genuinely absent (otherwise case A already answered)
     *   · rows were actually withheld — `*_withheld_rows > 0` is the sync saying «I had an amount
     *     and refused to convert it», which a summed original alone never says
     *   · the original is POSITIVE. Zero is the value a sum takes when there was nothing to add.
     *   · exactly one original currency, and a real one. Two unconvertible currencies cannot be
     *     added into one amount, and a label over their sum would be a wrong label — the same rule
     *     `money_original_currencies` already enforces for the reader.
     *
     * A converted value of 0.0 is a different thing entirely and stays answerable: the rate existed,
     * the conversion happened, and the answer was zero.
     *
     * @param  array<string, mixed>  $figures
     */
    private function moneyAnswerable(array $figures, string $key): bool
    {
        // A — converted, including a measured zero.
        if (($figures[$key] ?? null) !== null) {
            return true;
        }

        // B — withheld, and every part of the claim holds.
        $withheldRows = (int) ($figures[$key.'_withheld_rows'] ?? 0);
        $original = $figures[$key.'_original'] ?? null;
        $currency = $figures['money_original_currency'] ?? null;
        $currencies = (int) ($figures['money_original_currencies'] ?? 0);

        return $withheldRows > 0
            && is_numeric($original)
            && (float) $original > 0.0
            && $currencies === 1
            && is_string($currency)
            && trim($currency) !== '';
    }

    /**
     * Keep only the metrics THIS table can actually produce.
     *
     * `ObjectiveFamily` describes the canonical verdict for a family and is right at campaign level,
     * where `daily_metrics` carries leads, installs, registrations and in-app events.
     * `creative_daily_metrics` has none of those columns — a platform that breaks results down per
     * creative does not break every result type down — so a lead campaign's creative card would put
     * «no data» in its most prominent position, which is the opposite of an objective-aware card.
     *
     * The requirement is explicit: a metric must not reach the UI before the pipeline can supply it.
     * So the family list is filtered here rather than being trimmed at its definition, and the
     * definition stays true for the callers that CAN answer it.
     *
     * `spend` survives every filter, so a family whose whole verdict is unavailable still leads with
     * the figure that is always the question.
     *
     * @param  list<string>  $metrics
     * @return list<string>
     */
    /**
     * CONTENT-KPI-AVAILABILITY-001 — objective-aware AND availability-aware, in that order.
     *
     * Two filters, and they answer different questions. The first is about the PIPELINE: can this
     * service produce the metric at all? `ObjectiveFamily::App` names `installs` and `cpi`, and
     * `creative_daily_metrics` has no column for either — no creative will ever answer them, so a
     * card that promises one is promising an empty cell forever.
     *
     * The second is about THIS ROW: did the platform answer it, for this creative, in this window? A
     * sales creative whose family wants `revenue` and `roas` is not helped by two blank cells when
     * Snapchat reports neither at creative grain — and the same creative reports impressions, clicks
     * and CTR that the card had room to show.
     *
     * Order matters, and it is the whole design. The family's OWN metrics are kept first and in the
     * family's own order, because the first cell is the verdict and the objective is the only thing
     * that knows which figure that is. Only then is the remainder topped up, from the metrics that
     * are true of any campaign whatever it was bought for. A sales creative that CAN answer `orders`
     * still leads with `orders`; one that cannot does not get a blank where its verdict should be.
     *
     * Nothing here invents a value, and nothing is projected from another grain. A metric survives
     * only by being present in this row.
     *
     * With `$figures` null — a group with no aggregate, a card being described rather than rendered —
     * only the pipeline filter applies, which is the behaviour every caller had before.
     *
     * @param  list<string>  $metrics
     * @param  array<string, mixed>|null  $figures
     * @return list<string>
     */
    private function supportable(array $metrics, ?array $figures = null): array
    {
        $producible = fn (string $key): bool => array_key_exists($key, self::SUMS)
            || in_array($key, self::DERIVED, true);

        $kept = array_values(array_filter($metrics, $producible));

        if ($figures !== null) {
            $kept = array_values(array_filter($kept, fn (string $k): bool => $this->answerable($figures, $k)));
        }

        /*
         * Top up to the number of cells the card renders.
         *
         * `Unknown`'s metrics are the ones true of every campaign whatever it was bought to do, which
         * is exactly what a family that has run out of answerable figures needs. When `$figures` is
         * given these are held to the same availability test as the family's own — so a creative that
         * genuinely reported four figures shows four, and one that reported two shows two rather than
         * two figures and two apologies.
         */
        foreach (ObjectiveFamily::Unknown->headlineMetrics() as $universal) {
            if (count($kept) >= self::HEADLINE_MINIMUM) {
                break;
            }

            if (in_array($universal, $kept, true) || ! $producible($universal)) {
                continue;
            }

            if ($figures === null || $this->answerable($figures, $universal)) {
                $kept[] = $universal;
            }
        }

        /*
         * `spend` is the last resort and only when nothing at all survived — it is the one question
         * asked of every campaign. It is NOT added when figures were supplied and spend is not among
         * them: a card is better with three honest cells than four where the fourth is «no data».
         */
        if ($kept === []) {
            return $figures === null || $this->answerable($figures, 'spend') ? ['spend'] : [];
        }

        return $kept;
    }

    /** The family whose KPIs this objective is judged by — see {@see CampaignObjective::family()}. */
    public function familyFor(?string $objective): ObjectiveFamily
    {
        $case = $objective === null ? null : CampaignObjective::tryFrom($objective);

        return $case?->family() ?? ObjectiveFamily::Unknown;
    }

    public function pathFor(?string $objective): MarketingPath
    {
        $case = $objective === null ? null : CampaignObjective::tryFrom($objective);

        return $case?->path() ?? MarketingPath::Awareness;
    }

    /**
     * Whether two creatives may be ranked against each other on one axis, and why not when they cannot.
     *
     * §15.7 forbids declaring an overall «winner» between content serving different objectives. The
     * honest comparison is per-metric — best reach, best CTR, best CPA — and this returns the reason
     * so the UI can say it rather than silently dropping the verdict.
     *
     * @return array{comparable: bool, reason: string|null, reason_ar: string|null}
     */
    public function comparable(?string $objectiveA, ?string $objectiveB): array
    {
        $pathA = $this->pathFor($objectiveA);
        $pathB = $this->pathFor($objectiveB);

        if ($pathA === $pathB) {
            return ['comparable' => true, 'reason' => null, 'reason_ar' => null];
        }

        return [
            'comparable' => false,
            'reason' => "These creatives were bought for different jobs ({$pathA->value} vs {$pathB->value}), so one overall winner would be misleading. Compare them metric by metric instead.",
            'reason_ar' => 'المحتويان اشتُريا لهدفين مختلفين ('.$pathA->labels()['ar'].' مقابل '.$pathB->labels()['ar'].')، فإعلان فائز واحد سيكون مضلّلًا. قارنهما مؤشرًا بمؤشر.',
        ];
    }
}

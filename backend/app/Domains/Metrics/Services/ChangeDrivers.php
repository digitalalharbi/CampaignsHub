<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Services;

use App\Domains\Metrics\Enums\MoneyState;
use App\Domains\Metrics\ValueObjects\MoneyScope;
use Illuminate\Support\Carbon;

/**
 * ANALYTICS-DIFFERENTIATION-001 — what moved, and WHICH entity moved it.
 *
 * ## Why this exists, and why a dashboard cannot answer it
 *
 * The dashboard says «spend rose 14%». That is a fact about the account, and there is nothing a
 * reader can do with it: the rise is the sum of every platform, campaign and ad set underneath, some
 * of which went up, some of which went down, and the ones that matter are usually not the biggest.
 * «Why» is a question about CONTRIBUTION, and contribution is arithmetic the reader cannot do from a
 * total — it needs the same metric, over both windows, split by an entity.
 *
 * So this decomposes one metric's period-over-period change into per-entity contributions: how much
 * of the account's movement each platform, campaign or ad set is responsible for, in the metric's
 * own unit, with its share of the total movement beside it.
 *
 * ## What it refuses, and why the refusals are the useful part
 *
 * - **No previous window** → no decomposition. A first period has nothing to have moved FROM, and a
 *   «driver» computed against zero is just the period's own ranking wearing the word «change».
 * - **A metric that does not add up** → refused outright. Spend, clicks, impressions and results are
 *   SUMS: the parts add to the whole, so a contribution is meaningful. CPA, ROAS, CTR and every
 *   other ratio are not — one campaign's CPA and another's do not add to the account's, and a
 *   «contribution to CPA» would be a number with no referent. This is the single most tempting wrong
 *   answer in the whole feature, so it is refused in one place rather than avoided at each call site.
 * - **A withheld figure** → the entity is listed as unquantifiable rather than dropped or counted as
 *   zero. A platform whose spend awaits an exchange rate did not contribute nothing.
 *
 * @see MetricsAggregator::byProvider() for the per-window sums this reads
 */
final class ChangeDrivers
{
    /**
     * The metrics whose parts genuinely add to their whole.
     *
     * Anything absent from this list is a ratio or a derived rate, and has no decomposition. See the
     * class note: this is the refusal that keeps the feature honest.
     */
    private const ADDITIVE = [
        'spend', 'impressions', 'clicks', 'conversions', 'revenue', 'reach',
        'landing_page_views', 'leads', 'qualified_leads', 'purchases',
        'installs', 'registrations', 'in_app_events', 'engagements',
        'video_views', 'video_completions', 'add_to_cart', 'checkout',
    ];

    public function __construct(
        private readonly MetricsAggregator $metrics,
        /**
         * Ad-set rows for a window — supplied, not fetched.
         *
         * Every other dimension comes off `MetricsAggregator`, which is already scoped to the
         * caller's reach. Ad sets live in `entity_daily_metrics` behind an aggregator that takes a
         * PROJECT id, and this class deliberately knows nothing about projects or requests: giving
         * it one so it could build the query itself would put the scope decision in the arithmetic,
         * which is where a widened ceiling hides best. The controller, which already resolved the
         * project and checked the permission, hands over a closure `(from, to) => rows` instead.
         *
         * Null where the caller cannot supply them, and then the dimension refuses rather than
         * quietly answering about providers instead.
         *
         * @var null|(callable(Carbon, Carbon): list<array<string, mixed>>)
         */
        private $adSetRows = null,
    ) {}

    /** Whether a decomposition of this metric would mean anything at all. */
    public static function decomposable(string $metric): bool
    {
        return in_array($metric, self::ADDITIVE, true);
    }

    /**
     * One metric's movement, split by the entity that produced it.
     *
     * `by` is the dimension: `provider` (which platform), `account` (which ad account inside it),
     * `campaign` (which campaign), or `objective` (what the money was BOUGHT for — the axis a mix
     * shift hides behind).
     * The window and its comparison are given rather than derived, so the caller's «previous period»
     * and this decomposition's cannot disagree about which days they mean.
     *
     * @return array{
     *     metric: string, by: string, decomposable: bool, reason: ?string,
     *     current: float, previous: float, change: float, change_pct: ?float,
     *     drivers: list<array{key: string, name: ?string, provider: ?string, current: float, previous: float, change: float, share: ?float, direction: string}>,
     *     unquantifiable: list<string>
     * }
     */
    public function forMetric(
        string $metric,
        string $by,
        Carbon $from,
        Carbon $to,
        ?Carbon $prevFrom,
        ?Carbon $prevTo,
    ): array {
        $empty = [
            'metric' => $metric,
            'by' => $by,
            'decomposable' => false,
            'reason' => null,
            'current' => 0.0,
            'previous' => 0.0,
            'change' => 0.0,
            'change_pct' => null,
            'drivers' => [],
            'unquantifiable' => [],
        ];

        if (! self::decomposable($metric)) {
            // A ratio has no parts that add to it — see the class note.
            return ['reason' => 'metric_is_not_additive'] + $empty;
        }

        if ($prevFrom === null || $prevTo === null) {
            return ['reason' => 'no_previous_period', 'decomposable' => true] + $empty;
        }

        $now = $this->rowsFor($by, $from, $to);
        $before = $this->rowsFor($by, $prevFrom, $prevTo);

        $keys = array_values(array_unique([...array_keys($now), ...array_keys($before)]));

        $drivers = [];
        $unquantifiable = [];

        foreach ($keys as $key) {
            $c = $this->readable($now[$key] ?? null, $metric);
            $p = $this->readable($before[$key] ?? null, $metric);

            /*
             * No single figure on either side is a WITHHELD amount, not a zero — FX-001.
             *
             * The aggregator coalesces a withheld money row to 0 for arithmetic, which is correct for
             * summing and a lie here: an entity whose spend awaits an exchange rate did not contribute
             * nothing to the account's movement. Counting it as zero would hand its share to whichever
             * entity happened to be measurable, which is a false attribution rather than a missing one.
             */
            if ($c === null || $p === null) {
                $unquantifiable[] = (string) ($now[$key]['name'] ?? $before[$key]['name'] ?? $key);

                continue;
            }

            /*
             * An entity that had none of this metric in either window is not a driver of anything.
             *
             * Without this, asking «what drove purchases» on an account that reports no purchases
             * returns a list of platforms each contributing zero — a table of nothing, which reads as
             * an answer. The absence is stated once, in `reason`, instead.
             */
            if ((float) $c === 0.0 && (float) $p === 0.0) {
                continue;
            }

            $drivers[] = [
                'key' => (string) $key,
                'name' => $now[$key]['name'] ?? $before[$key]['name'] ?? null,
                // The platform survives when an account's name does not — see `rowsFor()`.
                'provider' => $now[$key]['provider'] ?? $before[$key]['provider'] ?? null,
                'current' => round((float) $c, 2),
                'previous' => round((float) $p, 2),
                'change' => round((float) $c - (float) $p, 2),
                'share' => null,
                'direction' => ((float) $c - (float) $p) >= 0 ? 'up' : 'down',
            ];
        }

        $current = round(array_sum(array_column($drivers, 'current')), 2);
        $previous = round(array_sum(array_column($drivers, 'previous')), 2);
        $change = round($current - $previous, 2);

        /*
         * Share is of the GROSS movement, not the net.
         *
         * Dividing by the net change is the obvious choice and it is wrong: when one platform rises
         * 5,000 and another falls 4,900, the net is 100 and the first platform's «share» becomes
         * 5000%. The reader's question is «how much of what happened was this one», and what happened
         * is the total distance travelled — so the denominator is the sum of the absolute moves.
         */
        $gross = array_sum(array_map(static fn ($d) => abs((float) $d['change']), $drivers));

        foreach ($drivers as $i => $d) {
            $drivers[$i]['share'] = $gross > 0 ? round(abs((float) $d['change']) / $gross, 4) : null;
        }

        // Biggest mover first, whichever direction it moved in — that is what «drivers» means.
        usort($drivers, static fn ($a, $b) => abs((float) $b['change']) <=> abs((float) $a['change']));

        return [
            'metric' => $metric,
            'by' => $by,
            'decomposable' => true,
            'reason' => $drivers === [] ? 'no_entity_reported_this_metric' : null,
            'current' => $current,
            'previous' => $previous,
            'change' => $change,
            'change_pct' => $previous > 0 ? round($change / $previous, 4) : null,
            'drivers' => $drivers,
            'unquantifiable' => array_values(array_unique($unquantifiable)),
        ];
    }

    /**
     * The figure this row actually STATES for a metric, or null where it states none.
     *
     * Money goes through the same contract as every other surface: a row that is partly converted and
     * partly withheld, or withheld across two currencies, has no single figure — and `spend` on it
     * reads 0 because the SQL coalesced it. Counts have no such state and are read directly.
     *
     * @param  array<string, mixed>|null  $row
     */
    private function readable(?array $row, string $metric): ?float
    {
        if ($row === null) {
            // Absent from this window entirely — genuinely zero of it, which is a real contribution.
            return 0.0;
        }

        if ($metric === 'spend' || $metric === 'revenue') {
            $scope = MoneyScope::of(
                (float) ($row[$metric] ?? 0),
                (int) ($row["{$metric}_withheld_rows"] ?? 0),
                (float) ($row["{$metric}_original"] ?? 0),
                (int) ($row['money_original_currencies'] ?? 0),
                $row['money_original_currency'] ?? null,
            );

            /*
             * Only a CONVERTED figure may be added to another entity's.
             *
             * `amount()` also answers for a fully-withheld row — the platform's own figure, in the
             * platform's own currency — and that is the right answer for a card showing one platform.
             * It is the wrong one here: this list adds movements together and expresses each as a
             * share of the total, so a figure in another currency would be summed with riyals. The
             * contract already distinguishes the two states; this asks for the one it can use.
             */
            return $scope->state === MoneyState::CompleteConverted || $scope->state === MoneyState::Zero
                ? $scope->amount()
                : null;
        }

        $value = $row[$metric] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * One window's rows for a dimension, keyed by entity id, with the entity's display name.
     *
     * Read through `MetricsAggregator` rather than a query of its own, so a driver's figures and the
     * table the reader opens next are the same arithmetic — including the money contract's nulls,
     * which is what `unquantifiable` above depends on.
     *
     * @return array<string, array<string, mixed>>
     */
    private function rowsFor(string $by, Carbon $from, Carbon $to): array
    {
        if ($by === 'ad_set') {
            return $this->adSetRowsFor($from, $to);
        }

        $rows = match ($by) {
            'campaign', 'objective' => $this->metrics->byCampaign($from, $to),
            'account' => $this->metrics->byAccount($from, $to),
            default => $this->metrics->byProvider($from, $to),
        };

        /*
         * The OBJECTIVE dimension is a fold of the campaign rows, not a query of its own.
         *
         * `byCampaign()` already carries each campaign's objective, and folding here rather than
         * grouping in SQL keeps one arithmetic: the objective decomposition and the campaign table a
         * reader opens next are the same numbers added up differently. It is also the dimension that
         * answers the question a platform split cannot — «the account spent the same and returned
         * less» is usually a MIX shift, money moving from one objective to another, and no amount of
         * per-platform detail shows it.
         */
        if ($by === 'objective') {
            $folded = [];

            foreach ($rows as $row) {
                $key = (string) ($row['objective'] ?? '');
                if ($key === '') {
                    continue;
                }

                $bucket = $folded[$key] ?? ['name' => $key];

                foreach ($row as $field => $value) {
                    if (! is_numeric($value)) {
                        continue;
                    }
                    $bucket[$field] = (float) ($bucket[$field] ?? 0) + (float) $value;
                }

                $folded[$key] = $bucket;
            }

            return $folded;
        }

        $out = [];
        foreach ($rows as $row) {
            /*
             * An ACCOUNT is keyed by its own id and NAMED by the account, not the platform.
             *
             * «Which platform moved» and «which account inside it moved» are different questions, and
             * an agency running four Meta accounts for one client can have a fall in one hidden by a
             * rise in another — the platform total says nothing happened.
             */
            $key = (string) match ($by) {
                'campaign' => $row['campaign_id'] ?? '',
                'account' => $row['account_id'] ?? '',
                default => $row['provider'] ?? '',
            };

            if ($key === '') {
                continue;
            }

            // Carried for the account dimension: the platform survives even when the name does not.
            $row['provider'] = $row['provider'] ?? null;
            $row['name'] = match ($by) {
                'campaign' => $row['campaign_name'] ?? null,
                /*
                 * A removed account keeps its spend and loses its name — and a UUID is not a label.
                 *
                 * `account_name` is null where the account has been deleted since its rows were
                 * ingested, or where the rows carry an id no account row matches. Falling back to the
                 * id put “7f3f1aa2-2736-5f14-…” in front of a reader, which is the raw-identifier
                 * defect this product has an E2E against. Null travels instead, and the surface says
                 * what it means in words — with the PLATFORM, which is the part still known.
                 */
                'account' => $row['account_name'] ?? null,
                default => $row['provider'] ?? null,
            };
            $out[$key] = $row;
        }

        return $out;
    }

    /**
     * The ad sets, keyed by their own id and named by their own name.
     *
     * This is the level a campaign total hides, and the one an operator can actually act on: a
     * campaign whose spend held steady while one ad set doubled and another stopped looks, at the
     * campaign grain, like a week where nothing happened.
     *
     * An ad set whose name has gone keeps its figures and loses its label — the same rule the
     * account dimension follows, and for the same reason: a UUID is not a name, so null travels and
     * the surface says what it means in words.
     *
     * @return array<string, array<string, mixed>>
     */
    private function adSetRowsFor(Carbon $from, Carbon $to): array
    {
        if ($this->adSetRows === null) {
            return [];
        }

        $out = [];

        foreach (($this->adSetRows)($from, $to) as $row) {
            $key = (string) ($row['entity_id'] ?? '');

            if ($key === '') {
                continue;
            }

            $row['name'] = $row['name'] ?? null;
            $out[$key] = $row;
        }

        return $out;
    }
}

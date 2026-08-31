<?php

declare(strict_types=1);

namespace App\Domains\Reports\Services;

use App\Domains\Campaigns\Creative\CreativeRanking;
use App\Domains\Campaigns\Creative\RankingDirection;
use App\Domains\Campaigns\Creative\RankingMetric;
use App\Domains\Campaigns\Enums\ObjectiveFamily;

/**
 * Ranks "top items" (campaigns today; ad-level once connectors provide it) by objective-appropriate
 * criteria and returns a human-readable REASON for each — never an opaque score.
 *
 * WHICH criterion belongs to which objective is no longer decided here: `RankingMetric` owns that, so
 * this report, the Pulse and the email digest cannot disagree about what «best» means. The caller
 * decides the level; this orders by the canonical metric and explains the result in words.
 */
final class CreativeRankingService
{
    /** Metrics an operator scans for by their short name, and the prefix each verdict carries. */
    private const ACRONYM = [
        'roas' => 'ROAS ', 'cpa' => 'CPA ', 'cpl' => 'CPL ', 'cpc' => 'CPC ', 'cpm' => 'CPM ',
        'cpe' => 'CPE ', 'cpi' => 'CPI ', 'ctr' => 'CTR ', 'aov' => 'AOV ', 'cost_per_view' => 'CPV ',
    ];

    public function __construct(private readonly CreativeRanking $ranking = new CreativeRanking) {}

    /** @param list<array<string, mixed>> $items rows with metric keys (spend, roas, cpa, ctr, ...) */
    public function rank(string $objective, array $items, int $limit = 5): array
    {
        $items = array_values(array_filter($items, fn ($i) => (float) ($i['spend'] ?? 0) > 0));
        $avgCpa = $this->average($items, 'cpa');
        $avgCtr = $this->average($items, 'ctr');

        [$sortKey, $direction, $reason] = $this->strategy($objective, $items);

        /*
         * No rankable metric means no leaders. An empty section is honest; an arbitrary five
         * creatives under the heading «الأفضل أداءً» is a claim the data does not support, and the
         * report is the copy a client keeps.
         */
        if ($sortKey === null) {
            return [];
        }

        usort($items, function ($a, $b) use ($sortKey, $direction) {
            $va = $a[$sortKey] ?? null;
            $vb = $b[$sortKey] ?? null;
            // Nulls always sort last.
            if ($va === null && $vb === null) {
                return 0;
            }
            if ($va === null) {
                return 1;
            }
            if ($vb === null) {
                return -1;
            }

            return $direction === 'desc' ? $vb <=> $va : $va <=> $vb;
        });

        return array_map(fn ($i) => $i + ['reason' => $reason($i, $avgCpa, $avgCtr)], array_slice($items, 0, $limit));
    }

    /**
     * REPORT-WORST-CREATIVES-001 — the creatives spending money and returning least.
     *
     * A report that only lists winners tells a reader what to keep and never what to stop, and
     * stopping is the cheaper decision. This is `rank()` read from the other end, by the same
     * objective-aware metric — an awareness creative is judged on CPM, a sales one on ROAS — so
     * «worst» always means worst at the thing the money was spent to buy.
     *
     * A creative whose ranking metric is NULL is excluded rather than placed last. It sorts last in
     * `rank()` because an absence must not win, and by the same reasoning it must not lose either:
     * «the platform reported no ROAS for this creative» is not «this creative returned nothing», and
     * naming it the worst performer in a client's report would be a claim nobody measured.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    public function worst(string $objective, array $items, int $limit = 5): array
    {
        [$sortKey, $direction] = $this->strategy($objective, $items);

        if ($sortKey === null) {
            return [];
        }

        // Spending, and actually measured on the metric it is being judged by.
        $measured = array_values(array_filter(
            $items,
            fn ($i) => (float) ($i['spend'] ?? 0) > 0 && ($i[$sortKey] ?? null) !== null,
        ));

        if ($measured === []) {
            return [];
        }

        $avgCpa = $this->average($measured, 'cpa');
        $avgCtr = $this->average($measured, 'ctr');

        // The same order as `rank()`, reversed: worst is the far end of «best».
        usort($measured, function ($a, $b) use ($sortKey, $direction) {
            $cmp = ($a[$sortKey] ?? 0) <=> ($b[$sortKey] ?? 0);

            return $direction === 'desc' ? $cmp : -$cmp;
        });

        $reason = $this->weakness($sortKey);

        return array_map(
            fn ($i) => $i + ['reason' => $reason($i, $avgCpa, $avgCtr)],
            array_slice($measured, 0, $limit),
        );
    }

    /**
     * Why this creative is on the list — the figure that put it there, not a verdict.
     *
     * Built from the metric that ACTUALLY decided the order, exactly as the leaders' reason already
     * was. This was a `match` on the objective with its own hardcoded phrasing per arm, and its
     * `default` arm named ROAS — so a scope ranked on spend, because spend was all the provider
     * reported, came back reading «أقل عائد على الإنفاق (ROAS —)». A sentence naming a different
     * number from the one that produced the order is worse than no sentence: the reader checks the
     * number they were given, finds nothing, and cannot tell whether the order or the figure is
     * wrong.
     *
     * `RankingMetric` carries the Arabic name and the direction, so the phrasing follows the metric
     * and a metric added to a layout is explained without editing this file.
     *
     * @return callable(array<string, mixed>, ?float, ?float): string
     */
    private function weakness(string $metric): callable
    {
        $spec = RankingMetric::of($metric);
        $label = $spec->labelAr;

        // «Worst» is the far end of «best», so the adjective flips: the worst of a cost metric is the
        // HIGHEST, and the worst of a return is the lowest.
        $lead = $spec->direction === RankingDirection::LowerIsBetter ? 'أعلى' : 'أقل';

        return function (array $i) use ($metric, $label, $lead): string {
            $value = $i[$metric] ?? null;

            if (! is_numeric($value)) {
                return sprintf('%s %s (—).', $lead, $label);
            }

            $shown = match ($metric) {
                'ctr', 'engagement_rate', 'conversion_rate', 'video_completion_rate' => $this->pct((float) $value),
                'roas' => $this->num((float) $value).'×',
                default => $this->fmt((float) $value),
            };

            return sprintf('%s %s (%s) في هذه الفترة.', $lead, $label, $shown);
        };
    }

    /**
     * The metric, its direction, and the sentence that explains the verdict — CREATIVE-RANK-001.
     *
     * The first two now come from `RankingMetric`, the one place that knows what an objective is
     * judged on and which way is better. This class decided both privately, and so did
     * `CreativePulse` and `DigestCreatives`, with three different metric sets between them — `leads`
     * in one of the three, `cpl` in none. «Best creative» was a different question depending on which
     * screen asked it.
     *
     * What stays here is the REASON: prose, in Arabic, naming the figure that produced the verdict.
     * That is a reporting concern and belongs to the report rather than to the ranking contract.
     *
     * The objective strings arriving here are the report's own vocabulary — `app_installs` rather
     * than `app` — so they are mapped to the canonical family instead of being assumed to match.
     *
     * @return array{0:?string,1:string,2:callable} sort key (null ⇒ this scope cannot be ranked),
     *                                              direction, reason builder
     */
    /**
     * Which metric an order over THESE rows rests on — null when nothing in the objective's layout
     * was reported.
     *
     * REPORT-AD-PREVIEW-001 §A — a section that calls ads «top performing» has to be able to say on
     * what, and the honest answer is sometimes «nothing anybody reported», which is why this returns
     * null rather than the objective's primary. The ranking itself already refuses to order such a
     * scope; this exposes the same decision so the page can print it.
     *
     * @param  list<array<string,mixed>>  $items
     */
    public function metricFor(string $objective, array $items = []): ?string
    {
        [$key] = $this->strategy($objective, $items);

        return $key;
    }

    private function strategy(string $objective, array $items = []): array
    {
        /*
         * An objective this map does not recognise is UNKNOWN, not Sales.
         *
         * `default => Sales` meant every unrecognised objective was ranked by ROAS — the exact thing
         * ANALYTICS-OBJECTIVE-SYSTEM-001 forbids, «All must never rank unlike objectives by one
         * universal metric such as ROAS». It is silent, too: an awareness report whose objective
         * string this map has not learned would be ordered by return on ad spend and read as though
         * somebody had chosen that. `Unknown` carries a null primary precisely so this case has an
         * answer that is not a guess.
         */
        $family = ObjectiveFamily::tryFrom($objective) ?? match ($objective) {
            'app_installs' => ObjectiveFamily::App,
            'conversions', 'purchases' => ObjectiveFamily::Sales,
            default => ObjectiveFamily::Unknown,
        };

        /*
         * Availability decides, within the objective's own layout.
         *
         * Ranking strictly by the primary ranks NOTHING when the provider did not return it — a
         * leads report whose rows carry `cpa` but no `cpl` would come back empty, which is worse than
         * the old private map it replaced. `resolveMetric` takes the primary when anything reports
         * it and otherwise the first secondary that does, in efficiency-before-volume order.
         */
        /*
         * `resolveMetric` alone. There is no fallback to the layout's primary, because that fallback
         * only ever fired in the one case it must not: `resolveMetric` already returns the primary
         * whenever any row reports it, and the first reported secondary otherwise — so reaching past
         * it means NOTHING in this objective's layout was reported. Taking the primary anyway ranked
         * creatives by a figure none of them had, and the reason line said so out loud: «أقل تكلفة
         * الألف ظهور (—)», an order explained by a number that is not there.
         */
        $key = $items === []
            /*
             * With no rows there is nothing to rank, and the honest answer to «what WOULD this be
             * judged on» is the objective's own primary — which is the question the contract parity
             * tests ask. Availability cannot decide when there is nothing available to inspect, and
             * answering null here would make the contract unaskable rather than making it truthful.
             */
            ? RankingMetric::forObjective($family)['primary']
            : $this->ranking->resolveMetric($items, $family);

        /*
         * Nothing in this objective's layout was reported by anyone, so there is no order to give.
         *
         * This used to end `?? 'roas'`, which contradicted the contract it was calling:
         * `resolveMetric` returns null on purpose, and its own docblock says «this scope cannot be
         * ranked» is an answer, and a better one than an arbitrary order. Falling through to ROAS
         * made that answer unreachable and put a revenue ordering on creatives nobody measured
         * revenue for — in a client's report, where an order reads as a judgement.
         */
        if ($key === null) {
            return [null, 'desc', static fn (): string => ''];
        }

        $spec = RankingMetric::of($key);
        $direction = $spec->direction === RankingDirection::LowerIsBetter ? 'asc' : 'desc';

        /*
         * The sentence names the figure that ACTUALLY produced the verdict.
         *
         * These were written per objective and each hardcoded its objective's primary — so a leads
         * report ranked on `cpa`, because the provider returned no `cpl`, still read «أقل تكلفة عميل
         * محتمل (CPL —)»: the right order explained by a figure that is not there. A reason that
         * names a different number from the one that decided the order is worse than no reason.
         *
         * `RankingMetric` already carries the Arabic name and the direction, so the phrasing follows
         * the metric — «أقل» for a cost, «أعلى» for a return — and a metric added to a layout is
         * explained without editing this file.
         */
        $label = $spec->labelAr;
        $lead = $spec->direction === RankingDirection::LowerIsBetter ? 'أقل' : 'أعلى';

        $reason = function (array $i) use ($key, $label, $lead): string {
            $value = $i[$key] ?? null;

            if (! is_numeric($value)) {
                // The row was ranked on something; if this one has no figure it was excluded, and
                // claiming a verdict for it would be inventing one.
                return sprintf('%s %s (—).', $lead, $label);
            }

            $shown = match ($key) {
                'ctr', 'engagement_rate', 'conversion_rate', 'video_completion_rate' => $this->pct((float) $value),
                'roas' => $this->num((float) $value).'×',
                default => $this->fmt((float) $value),
            };

            /*
             * The acronym travels with the Arabic name.
             *
             * «أقل تكلفة النتيجة (25)» is correct and unscannable: an operator reading a column of
             * verdicts looks for CPA, CPL, ROAS. The label says what the figure means; the acronym
             * is what they are searching for. Only the metrics that HAVE an acronym get one —
             * `leads` or `reach` would read absurdly as «(LEADS 40)».
             */
            return sprintf('%s %s (%s%s).', $lead, $label, self::ACRONYM[$key] ?? '', $shown);
        };

        return [$key, $direction, $reason];
    }

    private function average(array $items, string $key): ?float
    {
        $vals = array_filter(array_map(fn ($i) => $i[$key] ?? null, $items), fn ($v) => $v !== null);

        return $vals === [] ? null : array_sum($vals) / count($vals);
    }

    private function fmt(?float $v): string
    {
        return $v === null ? '—' : number_format($v, $v < 10 ? 2 : 0);
    }

    private function num(?float $v): string
    {
        return $v === null ? '—' : number_format($v, 2);
    }

    private function pct(?float $v): string
    {
        return $v === null ? '—' : number_format($v * 100, 2).'%';
    }
}

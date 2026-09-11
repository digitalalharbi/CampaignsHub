<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Services;

use Illuminate\Support\Carbon;

/**
 * CAMPAIGNS-LEDGER-001 — the operational ordering, on the server, so a page can be cut from it.
 *
 * The campaigns workspace opens on what is RUNNING: serving first, then switched-on-but-dark, then
 * stopped — and within each, the bigger spender first. That rule lived only in the browser, over the
 * whole project's campaigns, because the endpoint handed over the whole project's campaigns.
 *
 * Paginating without moving it here would have been the defect, not the fix: the server would cut by
 * `created_at` and the browser would re-order twenty-five rows, so «the most relevant campaigns»
 * would mean «the most relevant of the twenty-five newest» — and the ones behind the page boundary
 * would be invisible with nothing saying so.
 *
 * ## It is not a second analytics pipeline
 *
 * The two facts it reads — `status` and `last_active_on` — arrive on `MetricsAggregator::byCampaign()`
 * rows, whose own docblock says relevance ordering belongs to the operational surface that asks for
 * it. Nothing is re-aggregated here and no metric is recomputed; this sorts rows the canonical
 * aggregator produced.
 *
 * ## It mirrors the frontend rule deliberately
 *
 * `campaignRelevance.ts` states the same rule for the same reason `ClientBudgetRollup` mirrors
 * `portfolioBudget`: the browser still orders the rows it holds, and the two must not disagree about
 * which campaign leads. Both sides are asserted against the same cases.
 */
final class CampaignRelevance
{
    /** A campaign the platform reports as finished is finished, however much it spent. */
    public const NOT_RUNNING = ['paused', 'completed', 'archived'];

    /** Activity this recently, relative to the window's end, still counts as serving. */
    public const SERVING_WITHIN_DAYS = 3;

    /** Serving leads, then switched-on-but-dark, then stopped — the dark one is the problem. */
    private const ORDER = ['serving' => 0, 'idle' => 1, 'stopped' => 2];

    /**
     * @param  array{status?: string|null, last_active_on?: string|null}  $row
     * @return 'serving'|'idle'|'stopped'
     */
    public function of(array $row, string $windowEnd): string
    {
        $status = $row['status'] ?? null;

        if ($status !== null && in_array($status, self::NOT_RUNNING, true)) {
            return 'stopped';
        }

        /*
         * `unknown` and null are NOT read as stopped. The platform did not tell us the state; the
         * campaign's own activity is the only evidence there is, and treating missing information as
         * a claim that the campaign ended would be inventing the answer.
         */
        $last = $row['last_active_on'] ?? null;

        if ($last === null) {
            return 'idle';
        }

        return Carbon::parse($last)->diffInDays(Carbon::parse($windowEnd), absolute: false) <= self::SERVING_WITHIN_DAYS
            ? 'serving'
            : 'idle';
    }

    /**
     * Campaign ids, most relevant first.
     *
     * @param  list<array<string, mixed>>  $rows  `byCampaign()` rows, or anything carrying the same keys
     * @return list<string>
     */
    public function order(array $rows, string $windowEnd): array
    {
        usort($rows, function (array $a, array $b) use ($windowEnd): int {
            $byState = self::ORDER[$this->of($a, $windowEnd)] <=> self::ORDER[$this->of($b, $windowEnd)];

            if ($byState !== 0) {
                return $byState;
            }

            $bySpend = (float) ($b['spend'] ?? 0) <=> (float) ($a['spend'] ?? 0);

            if ($bySpend !== 0) {
                return $bySpend;
            }

            /* A key that cannot move, so a list of equal campaigns does not reshuffle between pages. */
            return (string) ($a['campaign_id'] ?? '') <=> (string) ($b['campaign_id'] ?? '');
        });

        return array_map(static fn (array $r): string => (string) ($r['campaign_id'] ?? ''), $rows);
    }
}

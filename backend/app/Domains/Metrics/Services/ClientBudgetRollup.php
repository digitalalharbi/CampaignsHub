<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Services;

/**
 * BUDGET-GOVERNANCE-001 — the CLIENT rung: one client's whole budget, across its projects.
 *
 * The hierarchy the owner asked for is Client → Project → Platform → Account → Campaign. The four
 * lower rungs are grains of one query and were already there or added beside it; the client rung is
 * not — it is a ROLL-UP across projects, and the agency dashboard carried counts of clients,
 * projects and campaigns with no money on it at all.
 *
 * ## The arithmetic is the campaigns overview's, deliberately
 *
 * `portfolioBudget()` on the frontend states the same rule for one project's campaigns, and this
 * mirrors it rather than inventing a second one:
 *
 *   - only rows the aggregator marked `comparable` are added, because the others each name a reason
 *     their figures are not a like-for-like quantity;
 *   - the ratio is recomputed from the totals ONCE, never averaged — a 100 budget running at 3×
 *     beside a 10,000 budget at 0.9× averages to «about 2×» while the client is comfortably fine;
 *   - what is excluded is COUNTED, because a client total that quietly drops a campaign is worse
 *     than one that says it did;
 *   - two currencies among the comparable rows refuses the total rather than adding them.
 *
 * The properties are asserted on both sides, so the two cannot drift into disagreeing about the same
 * client on two screens.
 */
final class ClientBudgetRollup
{
    /**
     * @param  list<array<string, mixed>>  $rows  campaign rows from `MetricsAggregator::budgetPacing()`
     * @return array{budget: float|null, spent: float|null, remaining: float|null, projected: float|null, pace: float|null, currency: string|null, currencies: int, excluded: int, unmeasured: int, campaigns: int}
     */
    public function of(array $rows): array
    {
        /*
         * Counted by name, not filed under «excluded»: a campaign whose project's window holds no
         * measured row has no figure (Owner directive 2026-10-09 §19), which is a different fact from
         * a campaign with no budget or a budget in another currency — and the reader is told which.
         */
        $unmeasured = count(array_filter(
            $rows,
            static fn (array $r): bool => ($r['pacing_basis'] ?? null) === 'nothing_measured_in_window',
        ));

        $empty = [
            'budget' => null, 'spent' => null, 'remaining' => null, 'projected' => null,
            'pace' => null, 'currency' => null, 'currencies' => 0, 'excluded' => 0,
            'unmeasured' => $unmeasured, 'campaigns' => count($rows),
        ];

        $usable = array_values(array_filter(
            $rows,
            static fn (array $r): bool => ($r['pacing_basis'] ?? null) === 'comparable' && (float) ($r['budget'] ?? 0) > 0,
        ));

        /*
         * Committed money counts whether or not it has started moving. A campaign whose window holds
         * no measured row still holds its budget — what is unknown is its SPEND, not its commitment —
         * so its budget joins the client's committed total and its currency joins the comparability
         * check, while spend, remaining and pace are stated only when every part of them is measured.
         */
        $committed = array_values(array_filter(
            $rows,
            static fn (array $r): bool => ($r['pacing_basis'] ?? null) === 'nothing_measured_in_window' && (float) ($r['budget'] ?? 0) > 0,
        ));

        $excluded = count($rows) - count($usable) - $unmeasured;

        if ($usable === [] && $committed === []) {
            return ['excluded' => $excluded] + $empty;
        }

        $currencies = array_values(array_unique(array_filter(array_map(
            static fn (array $r): string => strtoupper((string) ($r['budget_currency'] ?? '')),
            [...$usable, ...$committed],
        ))));

        if (count($currencies) > 1) {
            return ['currencies' => count($currencies), 'excluded' => $excluded] + $empty;
        }

        $budget = array_sum(array_map(static fn (array $r): float => (float) $r['budget'], [...$usable, ...$committed]));

        if ($committed !== []) {
            return [
                'budget' => round($budget, 2),
                'spent' => $usable === [] ? null : round(array_sum(array_map(static fn (array $r): float => (float) ($r['spent'] ?? 0), $usable)), 2),
                'remaining' => null,
                'projected' => null,
                'pace' => null,
                'currency' => $currencies[0] ?? null,
                'currencies' => count($currencies),
                'excluded' => $excluded,
                'unmeasured' => $unmeasured,
                'campaigns' => count($rows),
            ];
        }

        $spent = array_sum(array_map(static fn (array $r): float => (float) ($r['spent'] ?? 0), $usable));

        /*
         * A forecast of the sum needs every part forecast. One campaign the aggregator could not
         * project makes the client's projection unknowable — and says nothing about the budget and
         * spend beside it, which are still true and are still reported.
         */
        $projectable = array_reduce(
            $usable,
            static fn (bool $carry, array $r): bool => $carry && is_numeric($r['projected_spend'] ?? null),
            true,
        );

        $projected = $projectable
            ? array_sum(array_map(static fn (array $r): float => (float) $r['projected_spend'], $usable))
            : null;

        return [
            'budget' => round($budget, 2),
            'spent' => round($spent, 2),
            'remaining' => round($budget - $spent, 2),
            'projected' => $projected === null ? null : round($projected, 2),
            'pace' => $projected === null || $budget <= 0 ? null : round($projected / $budget, 4),
            'currency' => $currencies[0] ?? null,
            'currencies' => count($currencies),
            'excluded' => $excluded,
            'unmeasured' => $unmeasured,
            'campaigns' => count($rows),
        ];
    }
}

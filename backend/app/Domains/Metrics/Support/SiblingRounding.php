<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Support;

/**
 * MONEY-RECON-RUNGS-001 — siblings that add up to their parent, to the cent.
 *
 * ## The defect this exists for
 *
 * Every rung of a breakdown (platform, ad account, campaign, trend day, portfolio project) used to round its OWN
 * sum to two places. That is right for one row and wrong for a set: 100.00 SAR is 26.666667 USD, three such accounts
 * are 80.000001 USD, and three rows that each print 26.67 add up to 80.01 beside a parent that prints 80.00. Both
 * figures were «correct» in isolation; together they were a client-visible disagreement of one cent that no rate,
 * no sync and no scope could explain.
 *
 * ## The rule
 *
 * The PARENT is the source of truth. It is the exact sum of the stored `value` column rounded once, which is what
 * `MetricsAggregator::totals()` has always printed, so no headline figure changes. The children are then apportioned
 * to that total by the largest-remainder method: each child gets the cents it has earned, and the few cents left over
 * go to the children with the largest fractions. A child is therefore never more than one cent from its own rounding,
 * and the set always sums to its parent.
 *
 * Only the DISPLAYED sums move. `original_amount` — the figure compared with a provider — is never touched, and
 * ratios (ROAS, cost per result) are computed from unrounded bases before this runs and are not recomputed from it.
 */
final class SiblingRounding
{
    /** The money keys a breakdown row carries as converted sums. */
    public const MONEY_KEYS = ['spend', 'revenue'];

    /** The unrounded sums of one SQL group row, carried beside the rounded row until {@see apportion()} strips them. */
    public static function exact(object $groupRow): array
    {
        $out = [];
        foreach (self::MONEY_KEYS as $key) {
            $out[$key] = (float) ($groupRow->{$key} ?? 0);
        }

        return ['_exact' => $out];
    }

    /**
     * @param  list<array<string, mixed>>  $rows  each carrying `_exact`; the key is removed from the result
     * @return list<array<string, mixed>>
     */
    public static function apportion(array $rows): array
    {
        foreach (self::MONEY_KEYS as $key) {
            $exact = array_map(static fn (array $row): float => (float) ($row['_exact'][$key] ?? ($row[$key] ?? 0)), $rows);
            foreach (self::cents($exact) as $i => $cents) {
                if (array_key_exists($key, $rows[$i])) {
                    $rows[$i][$key] = $cents / 100;
                }
            }
        }

        return array_map(static function (array $row): array {
            unset($row['_exact']);

            return $row;
        }, $rows);
    }

    /**
     * Whole cents for each exact amount, summing to the exact total rounded once.
     *
     * @param  list<float>  $exact
     * @return list<int>
     */
    public static function cents(array $exact): array
    {
        $exact = array_values($exact);
        if ($exact === []) {
            return [];
        }

        $target = (int) round(round(array_sum($exact), 2) * 100);
        $floors = [];
        $fractions = [];
        foreach ($exact as $i => $amount) {
            // 1e-7 guards a binary 2666.9999999 that is, as a decimal(24,6) column, 2667.
            $scaled = round($amount * 100, 6);
            $floors[$i] = (int) floor($scaled);
            $fractions[$i] = $scaled - $floors[$i];
        }

        $remaining = max(0, min(count($exact), $target - array_sum($floors)));
        $order = array_keys($exact);
        usort($order, static fn (int $a, int $b): int => [$fractions[$b], $a] <=> [$fractions[$a], $b]);
        foreach (array_slice($order, 0, $remaining) as $i) {
            $floors[$i]++;
        }

        return $floors;
    }
}

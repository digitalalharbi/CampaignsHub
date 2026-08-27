<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Creative;

use App\Domains\Campaigns\Enums\ObjectiveFamily;

/**
 * The one place creatives are ordered best to worst — CREATIVE-RANK-001.
 *
 * Every consumer — `CreativeRankingService`, `CreativePulse`, `DigestCreatives`, the reports, the
 * Content workspace, the emails — calls this. For the same scope, objective, period, attribution,
 * currency and available data, every screen must name the same best creative and the same worst one.
 * Before this they could not: three private sorts over three different metric sets.
 *
 * ## Excluding is a verdict too, and it is stated
 *
 * A creative left out of a ranking has NOT performed badly — it has not been measured, or its money
 * cannot be compared. Returning it silently at the bottom would be the same defect the money contract
 * exists to prevent, one level up: absence rendered as a low score. So exclusions come back with a
 * reason, and every caller can say «ranked 12, excluded 3, because …».
 */
final class CreativeRanking
{
    public const NOT_REPORTED = 'not_reported';

    public const MONEY_NOT_COMPARABLE = 'money_not_comparable';

    public const NO_OBJECTIVE = 'no_objective';

    /**
     * Order rows best-first for the objective they were bought for.
     *
     * @param  list<array<string,mixed>>  $rows  each carrying the metric keys, plus optional
     *                                           `money_comparable` (false ⇒ its spend-derived figures
     *                                           cannot be compared with the others in this scope)
     * @param  string|null  $metric  override the objective's primary — for «rank by spend» and the
     *                               lead-quality/business-outcome modes, which are alternative
     *                               questions about the same creatives rather than a different engine
     * @return array{
     *     metric: ?string,
     *     direction: ?RankingDirection,
     *     ranked: list<array<string,mixed>>,
     *     excluded: list<array{row: array<string,mixed>, reason: string}>
     * }
     */
    public function rank(array $rows, ObjectiveFamily $family, ?string $metric = null): array
    {
        $key = $metric ?? RankingMetric::forObjective($family)['primary'];

        // An unclassified objective has no verdict to give. Every row comes back, in the order it
        // arrived, and the caller is told why nothing was ranked instead of being handed an order
        // that means nothing.
        if ($key === null) {
            return [
                'metric' => null,
                'direction' => null,
                'ranked' => [],
                'excluded' => array_map(
                    static fn (array $r): array => ['row' => $r, 'reason' => self::NO_OBJECTIVE],
                    $rows,
                ),
            ];
        }

        $spec = RankingMetric::of($key);   // throws on an undeclared metric — see the registry.

        $ranked = [];
        $excluded = [];

        foreach ($rows as $row) {
            $value = $row[$key] ?? null;

            if ($value === null) {
                // The provider returned nothing at this grain for this period. Not a zero.
                $excluded[] = ['row' => $row, 'reason' => self::NOT_REPORTED];

                continue;
            }

            if ($spec->isMoney && ($row['money_comparable'] ?? true) === false) {
                // Partial spend, or two currencies on one axis. A cost-per built on either is not a
                // smaller number, it is a different question.
                $excluded[] = ['row' => $row, 'reason' => self::MONEY_NOT_COMPARABLE];

                continue;
            }

            $ranked[] = $row;
        }

        usort($ranked, static fn (array $a, array $b): int => $spec->direction->compare(
            $a[$key] === null ? null : (float) $a[$key],
            $b[$key] === null ? null : (float) $b[$key],
        ));

        return [
            'metric' => $key,
            'direction' => $spec->direction,
            'ranked' => $ranked,
            'excluded' => $excluded,
        ];
    }

    /**
     * The strongest few, and the weakest few that were actually measured.
     *
     * «Worst» is drawn from the ranked set only. A creative the platform never reported is not the
     * worst performer — telling a client to stop it would be advice based on an absence.
     *
     * @param  list<array<string,mixed>>  $rows
     * @return array{best: list<array<string,mixed>>, worst: list<array<string,mixed>>, ranked_count: int, excluded_count: int, metric: ?string}
     */
    public function bestAndWorst(array $rows, ObjectiveFamily $family, int $take = 5, ?string $metric = null): array
    {
        $out = $this->rank($rows, $family, $metric);
        $ranked = $out['ranked'];

        return [
            'best' => array_slice($ranked, 0, $take),
            // Tail, reversed so the weakest reads first — and never overlapping `best` on a short list.
            'worst' => array_slice(array_reverse($ranked), 0, min($take, max(0, count($ranked) - $take))),
            'ranked_count' => count($ranked),
            'excluded_count' => count($out['excluded']),
            'metric' => $out['metric'],
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Creative;

/**
 * Which way is better — CREATIVE-RANK-001.
 *
 * The one piece of knowledge that was re-declared in four files with four different contents, and
 * the reason «best → worst» could not be trusted: a plain descending sort ranks the most expensive
 * cost-per-result first and calls it the winner.
 *
 * There is deliberately no `Unknown` case. A metric whose direction nobody has stated cannot be
 * ranked, and the registry refuses it rather than guessing — see {@see RankingMetric::of()}.
 */
enum RankingDirection
{
    /** More is better: ROAS, CTR, leads, purchases, reach. */
    case HigherIsBetter;

    /** Less is better: every cost-per — CPA, CPL, CPC, CPM, CPE, CPI, CPV. */
    case LowerIsBetter;

    /**
     * Order two values best-first, with «no value» always last.
     *
     * A null is not a bad score, it is the absence of one — a creative the provider reported nothing
     * for must not be ranked worst as though it had failed. It sorts last in BOTH directions, which
     * is the only position that never asserts anything about it.
     */
    public function compare(?float $a, ?float $b): int
    {
        if ($a === null && $b === null) {
            return 0;
        }
        if ($a === null) {
            return 1;
        }
        if ($b === null) {
            return -1;
        }

        return $this === self::HigherIsBetter ? $b <=> $a : $a <=> $b;
    }
}

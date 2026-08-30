<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Enums;

/**
 * How a limit is doing — BUDGET-GOVERNANCE-001.
 *
 * `Unknown` is a real answer and the most important one here. Spend that is withheld for want of an
 * exchange rate, or denominated differently from the limit, produces no comparable figure at all,
 * and a governance surface that showed «0% used» for it would be reporting safety it cannot see.
 */
enum SpendLimitState: string
{
    /** Comparable, and inside every threshold. */
    case Ok = 'ok';
    /** Comparable, and past the highest threshold below 100. */
    case Approaching = 'approaching';
    /** Comparable, and at or past the limit. */
    case Over = 'over';
    /** No comparable figure — see `basis` for which of the several reasons. */
    case Unknown = 'unknown';
}

<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Support;

/**
 * FINANCIAL-RATIOS-001 — the ONE arithmetic behind every spend-derived figure.
 *
 * The dashboard's aggregate, the content library's creative cards and the campaign creative list each
 * divided spend by results with their own guards, so «cost per result» had three definitions and a
 * creative could show a figure its campaign refused to. The rules are written once, here:
 *
 *   cost per X  = comparable spend ÷ X, only when spend EXISTS (null means withheld, hidden or
 *                 incomplete — never zero) and X is greater than zero.
 *   ROAS        = revenue ÷ spend, only when spend is greater than zero AND a revenue figure EXISTS. An
 *                 absent revenue (null) has no ROAS; a MEASURED zero is a real outcome — «nothing came back
 *                 for 900» — and is 0.0×. A caller whose revenue is coalesced to 0 when nobody reported any
 *                 (the campaign aggregate) cannot tell the two apart and passes null for a zero.
 *
 * A caller passes `null` for a spend it must not divide — withheld, hidden by the link, or partial — and
 * every cost ratio below becomes null with it. Nothing here reads a configuration: visibility is the
 * caller's decision, and arithmetic on a figure the reader may not see is the leak this prevents.
 */
final class FinancialRatios
{
    public static function costPer(?float $spend, ?float $results, int $decimals = 2, float $per = 1.0): ?float
    {
        if ($spend === null || $results === null || $results <= 0.0) {
            return null;
        }

        return round($spend / $results * $per, $decimals);
    }

    public static function roas(?float $spend, ?float $revenue, int $decimals = 3): ?float
    {
        if ($spend === null || $revenue === null || $spend <= 0.0 || $revenue < 0.0) {
            return null;
        }

        return round($revenue / $spend, $decimals);
    }
}
